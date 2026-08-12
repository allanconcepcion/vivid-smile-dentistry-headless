// Lazy-start the home-page hero video.
// Bytes are deferred (preload="none" + no autoplay) until the element is in
// view, then the video loads + plays. Reduced-motion users keep the poster.

const video = /** @type {HTMLVideoElement|null} */ (
  document.querySelector("[data-hero-video]")
);

if (video && !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
  const start = () => {
    video.load();
    const p = video.play();
    if (p && typeof p.catch === "function") {
      p.catch(() => {
        /* autoplay blocked — poster stays */
      });
    }
  };

  if ("IntersectionObserver" in window) {
    const io = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            start();
            io.disconnect();
            break;
          }
        }
      },
      { rootMargin: "100px" }
    );
    io.observe(video);
  } else {
    start();
  }
}
