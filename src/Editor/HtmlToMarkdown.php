<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * The converter behind the "convert to Markdown" action, over
 * league/html-to-markdown.
 *
 * ATX headers and hard breaks match what lin-codex's Markdown renderer
 * expects; strip_tags turns tags with no Markdown equivalent into their
 * text instead of leaving HTML in the body (the sanitizer already ran when
 * the article was read, so nothing dangerous is lost here); script and
 * style nodes are dropped whole. The library's default environment has no
 * table converter, so one is added: help articles carry tables, and
 * without it a table would collapse into a run of cell text.
 *
 * Not final on purpose: the writer's atomicity test substitutes a double
 * that throws mid-conversion, and the constructor is the only configuration.
 */
class HtmlToMarkdown
{
    private readonly HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'header_style' => 'atx',
            'strip_tags' => true,
            'hard_break' => true,
            'remove_nodes' => 'script style',
        ]);

        $this->converter->getEnvironment()->addConverter(new TableConverter);
    }

    public function convert(string $html): string
    {
        return $this->converter->convert($html);
    }
}
