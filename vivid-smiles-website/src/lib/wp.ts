/**
 * WPGraphQL client.
 *
 * Used at BUILD TIME only, from content-layer loaders. Nothing here ships to
 * the browser — the site stays fully static, and WordPress is never contacted
 * by a visitor.
 *
 * Failure policy: this module throws loudly on every failure path. A headless
 * build's worst outcome is not a crash, it's a *quiet* one — a network blip
 * yielding an empty `posts` array publishes a blog hub with zero posts and a
 * sitemap that drops every URL, which reads as a successful deploy. Failing the
 * build leaves the previous deployment serving.
 */

const ENDPOINT = import.meta.env.WP_GRAPHQL_ENDPOINT;

/** How long a single GraphQL request may take before the build gives up. */
const TIMEOUT_MS = 20_000;

/** Retries for transient failures (network reset, 502, or a CDN challenge). */
const MAX_ATTEMPTS = 5;

export class WordPressError extends Error {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options);
    this.name = "WordPressError";
  }
}

function endpoint(): string {
  if (!ENDPOINT) {
    throw new WordPressError(
      "WP_GRAPHQL_ENDPOINT is not set. Copy .env.example to .env (local) or set it " +
        "in the Vercel project's environment variables (deployed).",
    );
  }
  return ENDPOINT;
}

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Execute a GraphQL query and return `data`.
 *
 * GraphQL returns HTTP 200 for query-level errors, so a bare `res.ok` check
 * passes straight over a malformed query or a missing ACF field group. The
 * `errors` array is inspected explicitly.
 */
export async function wpQuery<T>(
  query: string,
  variables: Record<string, unknown> = {},
  label = "query",
): Promise<T> {
  let lastError: unknown;

  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
    // AbortSignal.timeout would be terser, but an explicit controller lets the
    // timeout be distinguished from a caller-side abort in the error message.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

    try {
      const res = await fetch(endpoint(), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ query, variables }),
        signal: controller.signal,
      });

      if (!res.ok) {
        const body = await res.text().catch(() => "");
        const message = `WPGraphQL ${label} failed: HTTP ${res.status} ${res.statusText}. ${body.slice(0, 300)}`;

        // 429 is retryable here, unlike most 4xx. The CMS sits behind
        // Cloudflare, which answers a bot challenge with 429 and an HTML
        // interstitial ("Just a moment..."). It is transient and clears on a
        // slower retry — treating it as permanent failed the build on the first
        // page render even though every earlier query had succeeded.
        if (res.status === 429) {
          const after = Number(res.headers.get("retry-after"));
          throw Object.assign(new WordPressError(message), {
            retryable: true,
            retryAfterMs: Number.isFinite(after) && after > 0 ? after * 1000 : undefined,
          });
        }

        // Other 4xx is a misconfiguration (wrong URL, auth, disabled plugin) and
        // will not fix itself — retrying only slows the build down. 5xx might.
        if (res.status < 500) throw new WordPressError(message);
        throw Object.assign(new WordPressError(message), { retryable: true });
      }

      const json = (await res.json()) as {
        data?: T;
        errors?: Array<{ message: string; path?: unknown[] }>;
      };

      if (json.errors?.length) {
        const detail = json.errors
          .map((e) => (e.path ? `${e.path.join(".")}: ${e.message}` : e.message))
          .join("; ");
        // Query-level errors are deterministic — never retry them.
        throw new WordPressError(`WPGraphQL ${label} returned errors: ${detail}`);
      }

      if (!json.data) {
        throw new WordPressError(`WPGraphQL ${label} returned no data.`);
      }

      return json.data;
    } catch (error) {
      lastError = error;

      const retryable =
        (error as { retryable?: boolean }).retryable === true ||
        (error instanceof Error && error.name === "AbortError") ||
        // fetch() rejects with a TypeError for DNS/connection-level failures.
        error instanceof TypeError;

      if (!retryable || attempt === MAX_ATTEMPTS) break;

      // Honour Retry-After when the CDN sends one; otherwise back off
      // quadratically. A challenge needs real time to clear, not 750ms.
      const hinted = (error as { retryAfterMs?: number }).retryAfterMs;
      await sleep(hinted ?? attempt * attempt * 1500);
    } finally {
      clearTimeout(timer);
    }
  }

  if (lastError instanceof WordPressError) throw lastError;

  throw new WordPressError(
    `WPGraphQL ${label} failed after ${MAX_ATTEMPTS} attempts against ${ENDPOINT ?? "(unset)"}. ` +
      `Is WordPress running? For local development: cd cms && npm start`,
    { cause: lastError },
  );
}

/**
 * Page through a WPGraphQL connection until it is exhausted.
 *
 * WPGraphQL caps `first` at 100 regardless of what is requested, so any
 * collection that might exceed that has to paginate or it silently truncates.
 */
export async function wpQueryAll<TNode>(
  query: string,
  select: (data: any) => { nodes: TNode[]; pageInfo: { hasNextPage: boolean; endCursor: string | null } },
  label = "query",
  pageSize = 100,
): Promise<TNode[]> {
  const all: TNode[] = [];
  let after: string | null = null;

  // Hard stop against a server that always reports hasNextPage — a runaway
  // loop here would hang the build indefinitely rather than fail it.
  for (let page = 0; page < 100; page++) {
    const data = await wpQuery<any>(query, { first: pageSize, after }, `${label} (page ${page + 1})`);
    const connection = select(data);

    all.push(...connection.nodes);

    if (!connection.pageInfo?.hasNextPage || !connection.pageInfo.endCursor) return all;
    after = connection.pageInfo.endCursor;
  }

  throw new WordPressError(`WPGraphQL ${label} did not terminate after 100 pages.`);
}

/**
 * Convert a WordPress HTML string to plain text.
 *
 * WordPress stores block-editor output as HTML; several components in this repo
 * render body copy through a plain Astro expression (`{r.body}`), which escapes
 * markup — so raw HTML would display as literal `<p>` tags on the page.
 */
export function htmlToText(html: string): string {
  return (
    html
      // Drop anything whose text content must not surface.
      .replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, "")
      // Preserve paragraph and line breaks as real newlines before tags go.
      .replace(/<\/(p|div|h[1-6]|li|blockquote)>/gi, "\n\n")
      .replace(/<br\s*\/?>/gi, "\n")
      .replace(/<[^>]+>/g, "")
      .replace(/&nbsp;/g, " ")
      .replace(/&amp;/g, "&")
      .replace(/&lt;/g, "<")
      .replace(/&gt;/g, ">")
      .replace(/&quot;/g, '"')
      .replace(/&#0?39;|&apos;|&#x27;/gi, "'")
      .replace(/&#8217;/g, "’")
      .replace(/&#8216;/g, "‘")
      .replace(/&#821[12];/g, "–")
      .replace(/[ \t]+/g, " ")
      .replace(/\n{3,}/g, "\n\n")
      .trim()
  );
}

/**
 * ACF select fields arrive from WPGraphQL for ACF as an array even when the
 * field is single-value, because the same field type backs multi-select.
 */
export function acfSingle(value: unknown): string | undefined {
  if (Array.isArray(value)) return value.length ? String(value[0]) : undefined;
  if (value === null || value === undefined || value === "") return undefined;
  return String(value);
}
