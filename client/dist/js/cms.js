jQuery.entwine('ss', function($){
  $('.docs-shadow-root').entwine({
    ScrollToCurrentHash: null,

    onmatch: function() {
        self = this;
        // Because of quirks with how entwine works, we can't just have this as a
        // normal entwine function, because instead of setting that function as the
        // hashchange event handler, it will set an internal entwine proxy
        // function as the event handler which doesn't do what we want.
        this.setScrollToCurrentHash(function() {
            const hash = window.location.hash.slice(1);
            if (!hash) {
                return;
            }
            // Use requestAnimationFrame to ensure the Shadow DOM layout is rendered
            requestAnimationFrame(() => {
                const targetElement = self[0].shadowRoot.getElementById(hash);
                if (targetElement) {
                    targetElement.scrollIntoView({behavior: 'smooth'});
                }
            });
        });
        window.addEventListener('hashchange', this.getScrollToCurrentHash());
        this.getScrollToCurrentHash()();
    },
    onunmatch: function() {
        window.removeEventListener('hashchange', this.getScrollToCurrentHash())
    },

    onclick: function(e) {
      const target = e.originalEvent?.originalTarget;
      if (!target || target.tagName.toLowerCase() !== 'a' || !target.href.includes('#')) {
        return;
      }
      const rootNode = target.getRootNode();
      const anchorId = target.href.split('#').at(-1);
      const anchorElement = rootNode.getElementById(anchorId);
      if (anchorElement) {
        e.preventDefault();
        anchorElement.scrollIntoView({behavior: 'smooth'});
        history.pushState(null, '', `#${anchorId}`); // @TODO only if not already the current state //@TODO not just the anchor, add the full URL first ugh
      }
    },
  });
});
// @TODO move this into src and add a webpack setup
