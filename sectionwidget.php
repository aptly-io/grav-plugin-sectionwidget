<?php

/* Copyright 2015 Francis Meyvis*/

/**
 * SectionWidget is a Grav plugin
 *
 * This plugin splits, at special markers, large content in sections. Only one section is visible at a time.
 *
 * Licensed under MIT, see LICENSE.
 *
 * @package     SectionWidget
 * @version     0.1.1
 * @link        <https://github.com/aptly-io/grav-plugin-sectionwidget>
 * @author      Francis Meyvis <https://aptly.io/contact>
 * @copyright   2015, Francis Meyvis
 * @license     MIT <http://opensource.org/licenses/MIT>
 *
 * @todo have a page's active section in a cookie (to later open with the last read section)
 */

namespace Grav\Plugin;     // use this namespace to avoids bin/gpm fails

use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Markdown\Parsedown;
use Grav\Common\Markdown\ParsedownExtra;


class SectionWidgetPlugin extends Plugin
{
    /** Enable all controls by default*/
    const CONTROLS_YAML = 'controls';
    const DEFAULT_CONTROLS = ['start', 'prev', 'next', 'last', 'fullmenu']; // full: show full whole page control

    /** Sections are by default recognized by this separator*/
    const SEPARATOR_YAML = 'marker';
    const DEFAULT_SEPARATOR   = '======';

    /** The section to show by default (0-based index)*/
    const INITIAL_SECTION_YAML = 'initial';
    const DEFAULT_INITIAL_SECTION = 0;               // 'all' also supported
    const INITIAL_ALL_SECTIONS = 'full';

    /** The prefix for the id of a div element wrapping each section*/
    const ID_SECTION_PREFIX = 'sw_section';

    /** The class of a div element wrapping each section*/
    const CLASS_SECTION_HIDEABLE = 'sw_hideable';

    const PREV_IDX_ID = -10;
    const NEXT_IDX_ID = -20;
    const FULL_IDX_ID = -30;


    protected $items;


    /** Return a list of subscribed events*/
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }


    /** Initialize the plug-in*/
    public function onPluginsInitialized()
    {
        /* Sommerregen explains this checks if the admin user is active.
         * If so, this plug-in disables itself.
         * rhukster mentions this is for speedup purposes related to the admin plugin
         */
        if ($this->isAdmin()) {
            $this->active = false;

        } else {

            if ($this->config->get('plugins.sectionwidget.enabled')) {
                // if the plugin is activated, then subscribe to these additional events
                $this->enable([
                    'onTwigTemplatePaths'    => ['onTwigTemplatePaths', 0],
                    'onTwigSiteVariables'    => ['onTwigSiteVariables', 0]
                ]);
            }
        }
    }


    /** Register the enabled plugin's template PATH*/
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }


    /** Setup the necessary assets to build the widget*/
    public function onTwigSiteVariables()
    {
        $page = $this->grav['page'];

        $config = $this->mergeConfig($page);
        if ($config->get('enabled', false)) {

            $assets = $this->grav['assets'];
            $assets->addJs('plugin://sectionwidget/assets/js/sectionwidget.js');
            if ($config->get('built_in_css', false)) {
                $assets->addCss('plugin://sectionwidget/assets/css/sectionwidget.css');
            }

            $this->enable([
                'onPageContentRaw'       => ['onPageContentRaw', 0],
            ]);
        }
    }


    /**
     * Process the page's Markdown content
     *
     * By converting the markdown to HTML first makes searching the markers easier
     *
     * @note inspirated by grav/system/src/Grav/Common/Page/Page.php
     */
    protected function processMarkdown($page)
    {
        $config = $this->grav['config'];

        $defaults = (array) $config->get('system.pages.markdown');
        if (isset($page->header()->markdown)) {
            $defaults = array_merge($defaults, $page->header()->markdown);
        }

        if ($defaults['extra']) {
            $parsedown = new ParsedownExtra($page, $defaults);
        } else {
            $parsedown = new Parsedown($page, $defaults);
        }

        return $parsedown->text($page->getRawContent());
    }


    /** Find all sections based on the section separator token*/
    private function findSections($content, $config)
    {
        $marker = $config->get(SectionWidgetPlugin::SEPARATOR_YAML,
            SectionWidgetPlugin::DEFAULT_SEPARATOR);
        $sep_regex = '~(<p>)?\s*' . $marker . '\s*(?P<title>.*)(?(1)</p>)~i';

        $sections = array();
        if (preg_match_all($sep_regex, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            // The reversing makes working with the offset possible (from back to front)
            $last_offs = strlen($content);
            foreach(array_reverse($matches) as $match) {
                // matches holds # of times the expression matched
                // the offset is only correct for the fully matched string
                // match holds [0] -> [0]: the full matched string, [1]: the offset
                //             [1] -> [0]: <p>, [1]: the offset
                //             ['title'] -> [0]: TITLE, [1]: the offset
                $title = trim($match['title'][0]);
                $offs = $match[0][1];
                $match_len = strlen($match[0][0]);
                $sections[] = array('title' => $title,
                                    'is_section' => true,
                                    // skip the matched pattern inside the section
                                    'content' => substr($content, $offs + $match_len, $last_offs - $offs - $match_len));
                $last_offs = $offs;
            }
            if (0 != $last_offs) {
                // Some data in front of the first section
                $sections[] = array('is_section' => false,
                                    'content' => substr($content, 0, $last_offs));
            }
        }

        return array_reverse($sections); // reverse the reverse stored sections
    }


    private function item($id, $title)
    {
        return array('id' => $id, 'title' => $title);
    }


    /** Create an array with titles and IDs for each section*/
    private function findTitles($sections)
    {
        $items = array();
        if (0 < count($sections)) {
            // Find the first occurrences of <hx>...</hx> in the section to be used as title
            // ~ marks the start and end of the pattern, i is an option for caseless matching
            // The pattern to match is
            // - optional <h[1-6]>
            // - the title looked for: everything til the < of the closing tag
            // - </h[1-6]>
            $h_regex = '~<[h][1-6][^<]*>(?P<title>[^<]*)</[h][1-6]>~i';
            $i = 0;
            foreach($sections as $section) {
                if ($section['is_section']) {
                    $title = $section['title'];
                    $offs = 0;
                    while (!$title) {
                        if (preg_match($h_regex, $section['content'], $matches, PREG_OFFSET_CAPTURE, $offs)) {
                            $title = trim($matches['title'][0]);
                            $offs += $matches[0][1] + strlen($matches[0][0]); // search further in next loop
                        } else {
                            break;
                        }
                    }
                    $items[] = $this->item($i, $title);
                    $i++;
                }
            }
        }

        return $items;
    }


    /** Set the twig variables now that the markdown content is searched for sections*/
    private function setupTwigVariables($content, $config)
    {
        $sections = $this->findSections($content, $config);
        $items = $this->findTitles($sections);

        if (0 < count($items)) {
            $vars = array();

            $vars['controls'] = $config->get(
                SectionWidgetPlugin::CONTROLS_YAML, SectionWidgetPlugin::DEFAULT_CONTROLS);

            $init = $config->get(SectionWidgetPlugin::INITIAL_SECTION_YAML,
                SectionWidgetPlugin::DEFAULT_INITIAL_SECTION);

            $vars['first'] = $items[0];
            $vars['prev'] = $this->item(SectionWidgetPlugin::PREV_IDX_ID, null);
            if (1 < count($items)) {
                $vars['next'] = $this->item(SectionWidgetPlugin::NEXT_IDX_ID, $items[1]['title']);
                $vars['last'] = $items[count($items) - 1];
            } else {
                $vars['next'] = $vars['last'] = null;
            }

            $full_page_mitem_msg = $this->grav['language']->translate(['FULL_PAGE_MITEM_MSG']);
            $vars['full'] = $this->item(SectionWidgetPlugin::FULL_IDX_ID, $full_page_mitem_msg);

            $items[] = $this->item(sizeof($items), $vars['full']['title']);

            if ($init == SectionWidgetPlugin::INITIAL_ALL_SECTIONS) {
                $current_id = count($items) - 1;
            } else {
                $current_id = intval($init);
                if (0 > $current_id or $current_id >= count($items)) {
                    $current_id = 0;
                }
            }
            $vars['current'] = $items[$current_id];

            $vars['menu'] = $items;

            $this->grav['twig']->twig_vars['sectionwidget'] = $vars;
        }

        return $items;
    }


    /** Find all sections to wrap these in their own div and have add an anchor*/
    private function replaceMarkers($content, $config)
    {
        // Find sections once more (other plugins might have changed the HTML)
        $sections = $this->findSections($content, $config);

        $menu_idx = 0;
        $content_new = '';
        foreach($sections as $section) {
            if (!$section['is_section']) {
                $content_new .= $section['content'];
            } else {
                $content_new .= '<div id="' . SectionWidgetPlugin::ID_SECTION_PREFIX . $this->items[$menu_idx]['id'];
                $content_new .= '" class="' . SectionWidgetPlugin::CLASS_SECTION_HIDEABLE . '">';
                $content_new .= '<a name="' . SectionWidgetPlugin::ID_SECTION_PREFIX . $this->items[$menu_idx]['id'] . '"></a>';
                $content_new .= $section['content'];
                $content_new .= '</div>';
                $menu_idx++;
            }
        }

        return $content_new;
    }


    /** Setup the necessary twig variables to build the widget*/
    public function onPageContentRaw(Event $event)
    {
        $page = $event['page'];
        $config = $this->mergeConfig($page);

        // Convert markdown to HTML; that's easier to process
        $content = $this->processMarkdown($page);
        // Examine the content for its sections and get all menu items
        $this->items = $this->setupTwigVariables($content, $config);
        if (0 < count($this->items)) {
            $this->enable([
                'onPageContentProcessed' => ['onPageContentProcessed', 0]
            ]);
        }
    }


    /** Replace section markers, insert div wrappers and anchors*/
    public function onPageContentProcessed(Event $event)
    {
        $page = $event['page'];
        $config = $this->mergeConfig($page);

        // get current rendered content
        $content = $page->getRawContent();
        // process the markers to recognize sections
        $page->setRawContent($this->replaceMarkers($content, $config));
    }

}
