// Developed with the assistance of Claude Code (claude.ai)

/*
 * Mermaid plugin – draws each ```mermaid block as a diagram, in light or dark mode to match the site's theme,
 * and draws them again if the visitor switches modes.
 *
 * Settings come from mermaid.php, in window.GravMermaid:
 *   darkMode   – how the theme shows dark mode: 'class', 'dark', 'light' or 'system' (a data-theme attribute on
 *                <html>, as Quark 2 themes set, is always checked first)
 *   lightTheme – Mermaid's colour theme for light mode
 *   darkTheme  – Mermaid's colour theme for dark mode
 *   look       – 'classic' or 'hand-drawn'
 *   font       – 'site' (the page's font) or 'mermaid' (Mermaid's own)
 *   width      – 'fit' (shrink to the content column) or 'natural' (full size, scrolling sideways when wide)
 */

// Wrapped in a function that runs straight away, so its names can't clash with the theme's scripts
(function () {
  var settings = window.GravMermaid;
  var html = document.documentElement;
  var systemDark = window.matchMedia('(prefers-color-scheme: dark)');

  // Mermaid's diagram types, each with its own width setting
  var DIAGRAM_TYPES = ['flowchart', 'sequence', 'gantt', 'journey', 'timeline', 'class', 'state', 'er', 'pie',
    'quadrantChart', 'xyChart', 'requirement', 'mindmap', 'gitGraph', 'c4', 'sankey', 'packet', 'block',
    'architecture', 'radar', 'kanban'];

  var diagrams = [];
  // Whether diagrams were last drawn in dark mode; null until first drawn
  var drawnDark = null;
  // The latest drawing, which the next one waits for
  var drawing = Promise.resolve();

  /**
   * Whether the page is showing dark mode right now.
   */
  function pageIsDark() {
    // Quark 2 themes always set data-theme to "dark" or "light", so it wins when present
    var dataTheme = html.getAttribute('data-theme');
    if (dataTheme === 'dark') return true;
    if (dataTheme === 'light') return false;

    if (settings.darkMode === 'class') return html.classList.contains('dark');
    if (settings.darkMode === 'dark') return true;
    if (settings.darkMode === 'light') return false;
    return systemDark.matches;
  }

  /**
   * Swaps each ```mermaid code block for an empty box, keeping the diagram's text so it can be drawn again later
   * (Mermaid replaces the text with the drawing).
   */
  function findDiagrams() {
    // Grav's Markdown gives <pre><code class="language-mermaid">
    var codeBlocks = document.querySelectorAll('pre > code.language-mermaid');
    for (var i = 0; i < codeBlocks.length; i++) {
      replaceBlock(codeBlocks[i]);
    }

    // The Codesh highlighting plugin (Helios) gives <pre class="language-mermaid">, with no <code>
    var preBlocks = document.querySelectorAll('pre.language-mermaid');
    for (var j = 0; j < preBlocks.length; j++) {
      replaceBlock(preBlocks[j]);
    }
  }

  /**
   * Swaps one code block for an empty diagram box.
   */
  function replaceBlock(block) {
    var pre = block.closest('pre');

    // Prism marks both <pre> and <code>, so a block can be found twice; skip it if already replaced
    if (!pre.isConnected) return;

    // Also replace a highlighting plugin's wrapper: .codesh-block (Codesh) or .code-toolbar (Prism)
    var outer = pre;
    if (pre.closest('.codesh-block')) outer = pre.closest('.codesh-block');
    if (pre.closest('.code-toolbar')) outer = pre.closest('.code-toolbar');

    var box = document.createElement('div');
    box.className = 'mermaid-diagram';
    // textContent turns &lt; back into <, giving Mermaid the text as written
    box.setAttribute('data-source', block.textContent);

    outer.parentNode.replaceChild(box, outer);
    diagrams.push(box);
  }

  /**
   * Mermaid's settings, from the plugin's settings and the current mode.
   */
  function mermaidSettings(dark) {
    var config = {
      startOnLoad: false,
      theme: settings.lightTheme,
      look: 'classic',
      // 'strict' stops diagram text from running scripts; links to pages (click lines) still work
      securityLevel: 'strict'
    };

    if (dark) {
      config.theme = settings.darkTheme;
    }

    if (settings.look === 'hand-drawn') {
      config.look = 'handDrawn';
    }

    if (settings.font === 'site') {
      var siteFont = window.getComputedStyle(diagrams[0]).fontFamily;
      config.fontFamily = siteFont;
      // Sequence diagrams have their own font settings
      config.sequence = { actorFontFamily: siteFont, noteFontFamily: siteFont, messageFontFamily: siteFont };
    }

    if (settings.width === 'natural') {
      // useMaxWidth shrinks a diagram to fit its column
      for (var i = 0; i < DIAGRAM_TYPES.length; i++) {
        var type = DIAGRAM_TYPES[i];
        // Keep any settings already added (such as sequence fonts)
        if (!config[type]) {
          config[type] = {};
        }
        config[type].useMaxWidth = false;
      }
    }

    return config;
  }

  /**
   * Draws (or redraws) every diagram in the current mode. Each drawing waits for the one before to finish, as
   * Mermaid's settings are shared.
   */
  function drawDiagrams() {
    // catch keeps a failed drawing from stopping all later ones
    drawing = drawing.then(drawInCurrentMode).catch(function (error) {
      console.error('Mermaid plugin:', error);

      // Try again on the next change, and meanwhile show each undrawn diagram's text instead of a blank space.
      // Unfinished print copies are removed, so printing uses the diagrams as shown.
      drawnDark = null;
      removePrintCopies();
      for (var i = 0; i < diagrams.length; i++) {
        if (!diagrams[i].hasAttribute('data-processed')) {
          diagrams[i].setAttribute('data-processed', 'true');
          diagrams[i].style.whiteSpace = 'pre';
        }
      }
    });
  }

  function drawInCurrentMode() {
    var dark = pageIsDark();
    if (dark === drawnDark) return;
    drawnDark = dark;

    removePrintCopies();

    // Put back each diagram's text, and clear Mermaid's "already drawn" mark so it draws it again
    for (var i = 0; i < diagrams.length; i++) {
      diagrams[i].textContent = diagrams[i].getAttribute('data-source');
      diagrams[i].removeAttribute('data-processed');
      diagrams[i].style.whiteSpace = '';
    }

    mermaid.initialize(mermaidSettings(dark));

    // suppressErrors shows an error box for a broken diagram and carries on with the rest
    return mermaid.run({ nodes: diagrams, suppressErrors: true }).then(function () {
      if (dark) {
        return drawPrintCopies();
      }
    });
  }

  /**
   * Pages print on white paper, so in dark mode each diagram also gets a light copy, kept off screen and shown
   * only when printing (a redraw when printing starts wouldn't finish in time).
   */
  function drawPrintCopies() {
    var copies = [];

    for (var i = 0; i < diagrams.length; i++) {
      var box = diagrams[i];
      var copy = document.createElement('div');
      copy.className = 'mermaid-print-copy';
      // Hidden from screen readers, which already have the diagram itself
      copy.setAttribute('aria-hidden', 'true');
      // Same width as the diagram, so it's drawn at the same size
      copy.style.width = box.clientWidth + 'px';
      copy.textContent = box.getAttribute('data-source');

      box.parentNode.insertBefore(copy, box.nextSibling);
      box.classList.add('has-print-copy');
      copies.push(copy);
    }

    mermaid.initialize(mermaidSettings(false));
    return mermaid.run({ nodes: copies, suppressErrors: true });
  }

  function removePrintCopies() {
    var copies = document.querySelectorAll('.mermaid-print-copy');
    for (var i = 0; i < copies.length; i++) {
      copies[i].parentNode.removeChild(copies[i]);
    }

    for (var j = 0; j < diagrams.length; j++) {
      diagrams[j].classList.remove('has-print-copy');
    }
  }

  function start() {
    if (!window.mermaid || !settings) return;

    findDiagrams();
    if (diagrams.length === 0) return;

    // Wait for the site's fonts, as Mermaid sizes boxes by measuring their text
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(drawAndWatch);
    } else {
      drawAndWatch();
    }
  }

  /**
   * Draws the diagrams, then watches for mode changes – only afterwards, as some themes (Helios) change <html>'s
   * classes while starting up, which would otherwise draw them before the fonts have loaded.
   */
  function drawAndWatch() {
    drawDiagrams();

    // Redraw when the theme's switch changes data-theme or the "dark" class on <html>...
    new MutationObserver(drawDiagrams).observe(html, {
      attributes: true,
      attributeFilter: ['data-theme', 'class']
    });

    // ...or when the visitor's system switches between light and dark
    if (systemDark.addEventListener) {
      systemDark.addEventListener('change', drawDiagrams);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
