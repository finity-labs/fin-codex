<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Commands\Concerns;

trait CanDeregisterPlugin
{
    protected function deregisterPlugin(string $panelPath): void
    {
        $content = file_get_contents($panelPath);

        if ($content === false) {
            $this->components->error("Could not read file: {$panelPath}");

            return;
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $pluginCall = 'FinCodexPlugin::make()';
        $importLine = "use FinityLabs\\FinCodex\\FinCodexPlugin;\n";

        if (! str_contains($content, $pluginCall)) {
            $this->components->warn('FinCodexPlugin is not registered in this panel provider.');

            return;
        }

        // Remove the plugin block: FinCodexPlugin::make() with any chained calls
        // Matches single-line: FinCodexPlugin::make(),
        // And multi-line:      FinCodexPlugin::make()
        //                          ->shortcut('?')
        //                          ->globalSearch(true),
        $content = preg_replace(
            '/\s*FinCodexPlugin::make\(\)(?:\s*->(?:[^\n]*(?:\n(?!\s*->)(?!\s*[)\]]))*?))*,?\s*\n?/',
            "\n",
            $content,
            1,
        );

        // Simpler approach if regex above didn't remove it: match from make() to next comma or closing bracket
        if (str_contains($content, $pluginCall)) {
            $content = $this->removePluginBlock($content);
        }

        // Clean up empty plugins block: ->plugins([\n            \n        ])
        $content = preg_replace(
            '/\s*->plugins\(\[\s*\]\)\n?/',
            "\n",
            $content,
        );

        // Remove the import line
        $content = str_replace($importLine, '', $content);

        file_put_contents($panelPath, $content);

        $this->components->info('FinCodexPlugin has been removed from the panel provider.');
    }

    /**
     * Remove the plugin block by tracking parentheses/brackets depth.
     */
    private function removePluginBlock(string $content): string
    {
        $start = strpos($content, 'FinCodexPlugin::make()');

        if ($start === false) {
            return $content;
        }

        // Walk backwards to include leading whitespace
        $blockStart = $start;
        while ($blockStart > 0 && $content[$blockStart - 1] === ' ') {
            $blockStart--;
        }
        if ($blockStart > 0 && $content[$blockStart - 1] === "\n") {
            $blockStart--;
        }

        // Walk forward from make() past the closing paren
        $pos = $start + strlen('FinCodexPlugin::make()');
        $len = strlen($content);

        // Consume chained method calls: ->method(...)
        while ($pos < $len) {
            // Skip whitespace
            while ($pos < $len && in_array($content[$pos], [' ', "\t", "\n", "\r"], true)) {
                $pos++;
            }

            // Check for -> chain
            if ($pos + 1 < $len && $content[$pos] === '-' && $content[$pos + 1] === '>') {
                $pos += 2;

                // Skip to opening paren
                while ($pos < $len && $content[$pos] !== '(') {
                    $pos++;
                }

                if ($pos < $len) {
                    // Skip balanced parens
                    $pos = $this->skipBalanced($content, $pos, '(', ')');
                }

                continue;
            }

            break;
        }

        // Skip trailing comma and whitespace
        if ($pos < $len && $content[$pos] === ',') {
            $pos++;
        }
        if ($pos < $len && $content[$pos] === "\n") {
            $pos++;
        }

        return substr($content, 0, $blockStart).substr($content, $pos);
    }

    private function skipBalanced(string $content, int $pos, string $open, string $close): int
    {
        $depth = 0;
        $len = strlen($content);
        $inString = false;
        $stringChar = '';

        while ($pos < $len) {
            $char = $content[$pos];

            if ($inString) {
                if ($char === '\\') {
                    $pos += 2;

                    continue;
                }
                if ($char === $stringChar) {
                    $inString = false;
                }
                $pos++;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $inString = true;
                $stringChar = $char;
                $pos++;

                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $pos + 1;
                }
            }

            $pos++;
        }

        return $pos;
    }
}
