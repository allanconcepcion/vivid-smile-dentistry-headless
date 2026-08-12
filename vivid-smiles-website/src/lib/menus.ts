/**
 * Navigation menus, sourced from WordPress at build time.
 *
 * Edited at wp-admin -> Appearance -> Menus. Two locations:
 *   primary  the header bar, its mega panels, and the mobile menu
 *   footer   the footer link columns
 *
 * This replaces navigation that was hardcoded across six components, with the
 * service links duplicated between MegaServices and MobileMenu and a comment
 * warning that both had to be edited by hand in the same change. One menu now
 * feeds all of them, so that class of drift is gone.
 *
 * WordPress hierarchy maps onto the existing panels:
 *   level 1  the top-level bar
 *   level 2  a panel's cards / columns / rows
 *   level 3  links inside a column
 *
 * Build-time only — see the note in contact.ts. Nothing in src/scripts/ imports
 * this, so it never reaches a browser bundle.
 */

import { wpQuery, WordPressError, acfSingle, htmlToText } from "./wp";

const MENU_QUERY = /* GraphQL */ `
  query Menu($location: MenuLocationEnum!) {
    menuItems(where: { location: $location }, first: 200) {
      nodes {
        id
        parentId
        label
        uri
        url
        description
        order
        target
        menuFields {
          eyebrow
          icon
          layout
          imagePosition
          image {
            node {
              sourceUrl
              altText
              mediaDetails {
                width
                height
              }
            }
          }
        }
      }
    }
  }
`;

export type MenuImage = { src: string; width: number; height: number; alt: string; position?: string };

export type MenuItem = {
  id: string;
  label: string;
  href: string;
  /** WordPress's menu-item description — the body copy in a mega panel. */
  body: string;
  /** Small label above the title. */
  eyebrow: string;
  /** Font Awesome class without the style prefix, e.g. "fa-tooth". */
  icon: string;
  /**
   * How a second-level item renders in its panel.
   *
   * "standalone_emergency" is a separate value rather than a flag inferred from
   * the URL — inferring "is this the emergency one?" breaks the moment someone
   * renames the page.
   */
  layout: "" | "column" | "standalone" | "standalone_emergency";
  image?: MenuImage;
  /** Opens in a new tab. */
  external: boolean;
  children: MenuItem[];
};

type RawNode = {
  id: string;
  parentId: string | null;
  label: string | null;
  uri: string | null;
  url: string | null;
  description: string | null;
  order: number | null;
  target: string | null;
  menuFields: {
    eyebrow: string | null;
    icon: string | null;
    layout: string[] | string | null;
    imagePosition: string | null;
    image: {
      node: {
        sourceUrl: string | null;
        altText: string | null;
        mediaDetails: { width: number | null; height: number | null } | null;
      } | null;
    } | null;
  } | null;
};

/** Menus change rarely and every page renders both. Fetch each once per build. */
const cache = new Map<string, Promise<MenuItem[]>>();

function toItem(node: RawNode, byParent: Map<string | null, RawNode[]>): MenuItem {
  const f = node.menuFields;
  const media = f?.image?.node;

  // `uri` is WordPress's resolved path and is null for a custom link, where the
  // authored value lives in `url` instead. Falling back keeps hand-entered
  // links (and anchors like /about-us/#team) working.
  const href = node.uri || node.url || "#";

  const image =
    media?.sourceUrl && media.mediaDetails?.width && media.mediaDetails?.height
      ? {
          src: media.sourceUrl,
          width: media.mediaDetails.width,
          height: media.mediaDetails.height,
          alt: media.altText?.trim() || node.label || "",
          position: f?.imagePosition?.trim() || undefined,
        }
      : undefined;

  return {
    id: node.id,
    // WordPress stores these HTML-encoded ("we&#8217;ll"), and Astro escapes
    // on output — so without decoding, the entity itself renders on the page.
    label: htmlToText(node.label ?? ""),
    href,
    body: htmlToText(node.description ?? ""),
    eyebrow: htmlToText(f?.eyebrow ?? ""),
    // ACF select fields come back as an array even when single-valued.
    icon: acfSingle(f?.icon) ?? "",
    layout: (acfSingle(f?.layout) ?? "") as MenuItem["layout"],
    image,
    external: node.target === "_blank",
    children: (byParent.get(node.id) ?? [])
      .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
      .map((child) => toItem(child, byParent)),
  };
}

async function fetchMenu(location: "PRIMARY" | "FOOTER"): Promise<MenuItem[]> {
  const data = await wpQuery<{ menuItems: { nodes: RawNode[] } }>(
    MENU_QUERY,
    { location },
    `${location.toLowerCase()} menu`,
  );

  const nodes = data.menuItems?.nodes ?? [];

  if (nodes.length === 0) {
    throw new WordPressError(
      `The ${location} menu is empty.\n` +
        `Assign one at wp-admin -> Appearance -> Menus (Manage Locations), or seed it:\n` +
        `  cd cms && npm run import:menus`,
    );
  }

  const byParent = new Map<string | null, RawNode[]>();
  for (const node of nodes) {
    const key = node.parentId ?? null;
    if (!byParent.has(key)) byParent.set(key, []);
    byParent.get(key)!.push(node);
  }

  return (byParent.get(null) ?? [])
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
    .map((node) => toItem(node, byParent));
}

export function getMenu(location: "PRIMARY" | "FOOTER"): Promise<MenuItem[]> {
  const key = location;
  if (!cache.has(key)) cache.set(key, fetchMenu(location));
  return cache.get(key)!;
}

/**
 * One top-level item by the path it points at.
 *
 * The panels each render a specific branch, and matching on href rather than
 * label means renaming "Services" to "Treatments" in WordPress does not
 * detach the panel from its content.
 */
export async function getMenuBranch(href: string): Promise<MenuItem | undefined> {
  const items = await getMenu("PRIMARY");
  return items.find((item) => item.href === href);
}
