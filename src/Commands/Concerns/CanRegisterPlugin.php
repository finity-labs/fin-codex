<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Commands\Concerns;

trait CanRegisterPlugin
{
    protected function registerPlugin(string $panelPath): void
    {
        $content = file_get_contents($panelPath);

        if ($content === false) {
            $this->components->error("Could not read file: {$panelPath}");

            return;
        }

        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $pluginCall = 'FinCodexPlugin::make()';
        $importLine = 'use FinityLabs\\FinCodex\\FinCodexPlugin;';

        // Already registered?
        if (str_contains($content, $pluginCall)) {
            $this->components->warn('FinCodexPlugin is already registered in this panel provider.');

            return;
        }

        // 1. Add import statement if missing
        if (! str_contains($content, $importLine)) {
            $content = $this->addImport($content, $importLine);
        }

        // 2. Add plugin to ->plugins([]) or create the block
        if (str_contains($content, '->plugins([')) {
            $content = $this->appendToExistingPlugins($content, $pluginCall);
        } else {
            $content = $this->createPluginsBlock($content, $pluginCall);
        }

        file_put_contents($panelPath, $content);

        $this->components->info('FinCodexPlugin has been registered successfully!');
    }

    protected function addImport(string $content, string $importLine): string
    {
        // Find the last "use " import line and append after it
        $lines = explode("\n", $content);
        $lastUseIndex = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/^use\s+/', trim($line))) {
                $lastUseIndex = $index;
            }
        }

        if ($lastUseIndex !== null) {
            array_splice($lines, $lastUseIndex + 1, 0, [$importLine]);

            return implode("\n", $lines);
        }

        return $content;
    }

    protected function appendToExistingPlugins(string $content, string $pluginCall): string
    {
        // Find "->plugins([" and detect its indentation, then append on the next line
        $pos = strpos($content, '->plugins([');

        if ($pos === false) {
            return $content;
        }

        // Detect indentation of the ->plugins([ line
        $lineStart = strrpos(substr($content, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineContent = substr($content, $lineStart, $pos - $lineStart);
        $baseIndent = '';
        if (preg_match('/^(\s*)/', $lineContent, $matches)) {
            $baseIndent = $matches[1];
        }

        // Plugin entry indentation = base + 4 spaces
        $pluginIndent = $baseIndent.'    ';

        // Find the position right after "->plugins([\n" or "->plugins(["
        $insertPos = strpos($content, '[', $pos);
        if ($insertPos === false) {
            return $content;
        }
        $insertPos++; // Move past the [

        $content = substr_replace(
            $content,
            "\n".$pluginIndent.$pluginCall.',',
            $insertPos,
            0,
        );

        return $content;
    }

    protected function createPluginsBlock(string $content, string $pluginCall): string
    {
        // Insert ->plugins([...]) before ->middleware([
        $target = '->middleware([';
        $pos = strpos($content, $target);

        if ($pos === false) {
            // Fallback: try ->authMiddleware([ or end of chain
            $target = '->authMiddleware([';
            $pos = strpos($content, $target);
        }

        if ($pos === false) {
            $this->components->warn('Could not find a suitable insertion point for ->plugins(). Please register FinCodexPlugin::make() manually.');

            return $content;
        }

        // Detect indentation of the target line
        $lineStart = strrpos(substr($content, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineContent = substr($content, $lineStart, $pos - $lineStart);
        $baseIndent = '';
        if (preg_match('/^(\s*)/', $lineContent, $matches)) {
            $baseIndent = $matches[1];
        }

        $pluginIndent = $baseIndent.'    ';

        $pluginsBlock = $baseIndent."->plugins([\n"
            .$pluginIndent.$pluginCall.",\n"
            .$baseIndent."])\n";

        $content = substr_replace($content, $pluginsBlock, $lineStart, 0);

        return $content;
    }
}
