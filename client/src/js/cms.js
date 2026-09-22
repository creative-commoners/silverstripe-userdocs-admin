import jQuery from 'jquery';

jQuery.entwine('ss', ($) => {
  $('.docs-shadow-root').entwine({
    // Because of quirks with how entwine works, we can't just have the callback
    // as a normal entwine function, because instead of setting that function as
    // the hashchange event handler, it will set an internal entwine proxy
    // function as the event handler which doesn't do what we want.
    ScrollToCurrentHash: null,

    onmatch() {
      const self = this;
      // When the hash changes and when the page first loads, if the hash in the
      // location bar matches the ID of an element in the shadow DOM, scroll to
      // that element.
      this.setScrollToCurrentHash(() => {
        const hash = window.location.hash.slice(1);
        if (!hash) {
          return;
        }
        // Use requestAnimationFrame to ensure the Shadow DOM layout has rendered
        requestAnimationFrame(() => {
          const targetElement = self[0].shadowRoot.getElementById(hash);
          if (targetElement) {
            targetElement.scrollIntoView({ behavior: 'smooth' });
          }
        });
      });
      window.addEventListener('hashchange', this.getScrollToCurrentHash());
      this.getScrollToCurrentHash()();
    },
    onunmatch() {
      window.removeEventListener('hashchange', this.getScrollToCurrentHash());
    },

    /**
     * When clicking an anchor link inside the shadow root, mimic normal browser
     * scroll-to-element behaviour for the element with the matching ID.
     */
    onclick(e) {
      const target = e.originalEvent?.originalTarget;
      if (!target || target.tagName.toLowerCase() !== 'a' || !target.href.includes('#')) {
        return;
      }
      const rootNode = target.getRootNode();
      const path = target.href.split('#').at(0);
      const anchorId = target.href.split('#').at(-1);
      const anchorElement = rootNode.getElementById(anchorId);
      if (path === window.location.pathname && anchorElement) {
        e.preventDefault();
        anchorElement.scrollIntoView({ behavior: 'smooth' });
        // Only alter history state if we're changing the hash
        if (window.location.hash !== `#${anchorId}`) {
          const newUrl = `${window.location.pathname}${window.location.search}#${anchorId}`;
          history.pushState({ hash: anchorId }, '', newUrl);
        }
      }
    },
  });
});
