<?php

// Developed with the assistance of Claude Code (claude.ai)

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;

/**
 * Mermaid – draws Mermaid diagrams (flowcharts, sequence diagrams, timelines and more) from ```mermaid blocks,
 * in light or dark mode to match the site's theme.
 *
 *   1. onPageContentRaw  – turns any [mermaid]...[/mermaid] tags (from the older Mermaid Diagrams plugin) into
 *                          ```mermaid blocks, so both are handled the same way
 *   2. onOutputGenerated – when a page has a diagram, adds Mermaid and js/mermaid-init.js to it
 *
 * Markdown turns a ```mermaid block into <pre><code class="language-mermaid">, and js/mermaid-init.js draws a
 * diagram in its place. The diagram text is never parsed as Markdown, so characters like * and _ are safe.
 *
 * Works with Grav 1.7 and Grav 2 - hibbittsdesign.org
 */
class MermaidPlugin extends Plugin
{
    // The Mermaid version in js/mermaid.min.js, added to its address so browsers fetch a new copy after an update.
    // To update Mermaid:
    //   1. Replace js/mermaid.min.js with https://cdn.jsdelivr.net/npm/mermaid@<version>/dist/mermaid.min.js
    //   2. Change the version here and in the README's Credits, and add a CHANGELOG entry
    //   3. Check examples/mermaid-test.md in light and dark mode, and printed; for a new major version, also
    //      check that mermaid.run, data-processed and DIAGRAM_TYPES in js/mermaid-init.js still match Mermaid
    const MERMAID_VERSION = '11.17.2';

    /**
     * The Grav events this plugin listens to, and the method each one calls.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * Turns on the plugin's other events, except in Admin, which has no diagrams to draw.
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onPageContentRaw'  => ['onPageContentRaw', 0],
            'onOutputGenerated' => ['onOutputGenerated', 0],
        ]);
    }

    /**
     * Turns [mermaid]...[/mermaid] tags into ```mermaid blocks, for sites moving from the Mermaid Diagrams plugin.
     */
    public function onPageContentRaw(Event $event)
    {
        if (!$this->config->get('plugins.mermaid.mermaid_tags')) {
            return;
        }

        $page = $event['page'];
        $raw = $page->getRawContent();

        // Most pages have no tags, so skip the search below
        if (strpos($raw, '[mermaid]') === false) {
            return;
        }

        // 1. Set code blocks (``` or ~~~ fences) aside as placeholders, so a [mermaid] example shown in one
        //    isn't turned into a diagram
        $code_blocks = [];
        $code_block_pattern = '/^[ \t]*(?<fence>`{3,}|~{3,})[\s\S]*?^[ \t]*\k<fence>[ \t]*$/m';

        $raw = preg_replace_callback($code_block_pattern, function ($matches) use (&$code_blocks) {
            $code_blocks[] = $matches[0];
            return '@@CODE-BLOCK-' . (count($code_blocks) - 1) . '@@';
        }, $raw);

        // null means the search failed (such as on a very large page), so leave the page as it is
        if ($raw === null) {
            return;
        }

        // 2. Turn each tag into a ```mermaid block; only a tag at the start of a line counts, so a mention in a
        //    sentence is left alone
        $tag_pattern = '/^[ \t]*\[mermaid\](?<diagram>[\s\S]*?)\[\/mermaid\]/m';

        $raw = preg_replace_callback($tag_pattern, function ($matches) {
            return "\n```mermaid\n" . trim($matches['diagram']) . "\n```\n";
        }, $raw);

        if ($raw === null) {
            return;
        }

        // 3. Put the code blocks back
        foreach ($code_blocks as $number => $code_block) {
            $raw = str_replace('@@CODE-BLOCK-' . $number . '@@', $code_block, $raw);
        }

        $page->setRawContent($raw);
    }

    /**
     * Adds Mermaid to the finished page, but only when the page has a diagram – Mermaid is a large file.
     *
     * This checks the whole page rather than its own content, so diagrams shown from other pages (such as in a
     * full text or print view) are drawn too.
     */
    public function onOutputGenerated()
    {
        $output = $this->grav->output;

        // A ```mermaid block becomes <code class="language-mermaid">; no such block means nothing to do
        if (!is_string($output) || strpos($output, 'language-mermaid') === false) {
            return;
        }

        // Only web pages (not RSS feeds or JSON), and only ones with a </body> to add the scripts before
        $page = $this->grav['page'];
        if (($page && $page->templateFormat() !== 'html') || stripos($output, '</body>') === false) {
            return;
        }

        // Insert (a length of 0 replaces nothing) before the last </body> only, in case the page shows HTML with
        // its own </body>
        $body_end = strripos($output, '</body>');
        $this->grav->output = substr_replace($output, $this->pageAdditions(), $body_end, 0);
    }

    /**
     * The styles and scripts added to a page with a diagram.
     */
    protected function pageAdditions()
    {
        // ?v= makes browsers fetch a new copy after a change
        $mermaid_js = Utils::url('plugin://mermaid/js/mermaid.min.js') . '?v=' . self::MERMAID_VERSION;
        $init_js = Utils::url('plugin://mermaid/js/mermaid-init.js') . '?v=' . filemtime(__DIR__ . '/js/mermaid-init.js');

        // Settings for js/mermaid-init.js
        $settings = [
            'darkMode' => $this->darkModeSignal(),
            'lightTheme' => $this->config->get('plugins.mermaid.light_theme', 'default'),
            'darkTheme' => $this->config->get('plugins.mermaid.dark_theme', 'dark'),
            'look' => $this->config->get('plugins.mermaid.look', 'classic'),
            'font' => $this->config->get('plugins.mermaid.font', 'site'),
            'width' => $this->config->get('plugins.mermaid.width', 'fit'),
        ];

        $align = $this->config->get('plugins.mermaid.align', 'center');
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'center';
        }

        // Diagrams scroll sideways when too wide, and stay hidden until drawn so their text doesn't flash up.
        // The .label rule undoes theme badge styles (such as Quark's capitals) on Mermaid's own "label" class.
        // In dark mode, each diagram has a light copy kept off screen, which replaces it when printing.
        $css = <<<CSS
.mermaid-diagram { margin: 1.5rem 0; overflow-x: auto; text-align: {$align}; }
.mermaid-diagram:not([data-processed]) { visibility: hidden; }
.mermaid-diagram .label, .mermaid-print-copy .label {
  font-size: inherit; text-transform: none; line-height: inherit;
  padding: 0; background: none; border-radius: 0;
}
.mermaid-print-copy { position: absolute; left: -10000px; top: 0; visibility: hidden; }
@media print {
  .mermaid-diagram.has-print-copy { display: none; }
  .mermaid-print-copy {
    position: static; width: auto !important; visibility: visible;
    margin: 1.5rem 0; text-align: {$align};
  }
}
CSS;

        return "\n<style>\n" . $css . "\n</style>\n"
             // JSON_HEX_TAG stops a setting from ending the <script> early
             . '<script>window.GravMermaid = ' . json_encode($settings, JSON_HEX_TAG) . ";</script>\n"
             . '<script src="' . $mermaid_js . '"></script>' . "\n"
             . '<script src="' . $init_js . '"></script>' . "\n";
    }

    /**
     * How the site's theme switches to dark mode, so diagrams can match it:
     *
     *   'class'  – the theme adds or removes a "dark" class on <html> (Helios)
     *   'dark'   – always dark: the theme's Dark Mode setting is On (Quark Open Publishing, Open Matter, Learn2
     *              with Git Sync), or this plugin's Dark Mode is Always Dark
     *   'light'  – always light: the theme's Dark Mode setting is Off, or this plugin's is Always Light
     *   'system' – follow the visitor's light or dark system setting (Dark Mode set to Auto, or any other theme)
     *
     * Themes that set data-theme="dark" or "light" on <html> (Quark 2, Quark 2 Open Publishing) need no value
     * here: js/mermaid-init.js checks data-theme first, so it always wins.
     *
     * Theme and plugin details this plugin relies on – if one changes, update the code that uses it:
     *   - Quark 2 themes: data-theme on <html> – js/mermaid-init.js
     *   - Helios: the "dark" class on <html>, and its appearance.theme setting – here and js/mermaid-init.js
     *   - Quark Open Publishing, Open Matter, Learn2 with Git Sync: dark_mode.mode, enabled or disabled – here
     *   - Codesh (Helios): <pre class="language-mermaid"> in a .codesh-block – js/mermaid-init.js
     *   - Prism: the .code-toolbar wrapper – js/mermaid-init.js
     *   - Quark's Spectre CSS: the .label class – the CSS in pageAdditions
     */
    protected function darkModeSignal()
    {
        // Lets a site choose, for a theme this plugin doesn't know
        $override = $this->config->get('plugins.mermaid.dark_mode', 'auto');
        if (in_array($override, ['dark', 'light', 'system'], true)) {
            return $override;
        }

        // Helios themes have an Appearance setting and manage the "dark" class themselves
        if ($this->config->get('theme.appearance.theme') !== null) {
            return 'class';
        }

        // Quark Open Publishing, Open Matter and Learn2 with Git Sync have a Dark Mode setting
        $theme_dark_mode = $this->config->get('theme.dark_mode.mode');
        if ($theme_dark_mode === 'enabled') {
            return 'dark';
        }
        if ($theme_dark_mode === 'disabled') {
            return 'light';
        }

        // Quark 2 themes are handled by data-theme in js/mermaid-init.js; everything else follows the system
        return 'system';
    }
}
