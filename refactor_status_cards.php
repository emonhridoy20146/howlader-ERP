<?php

$directory = __DIR__.'/resources/views';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());

        // Flexible pattern
        $pattern = '/<div[^>]*class="[^"]*rounded-xl bg-white p-4 ring-1 ring-slate-200[^"]*"[^>]*>\s*<p[^>]*class="[^"]*text-xs text-slate-500[^"]*"[^>]*>(.*?)<\/p>\s*<p[^>]*class="[^"]*mt-1 text-2xl font-semibold\s*([^"]*)"[^>]*>(.*?)<\/p>\s*<\/div>/s';

        $count = 0;
        $newContent = preg_replace_callback($pattern, function ($matches) {
            $label = trim($matches[1]);
            $classes = trim($matches[2]);
            $value = trim($matches[3]);

            if (! empty($classes)) {
                return "<x-status-card label=\"$label\">\n\t\t\t<span class=\"$classes\">$value</span>\n\t\t</x-status-card>";
            } else {
                return "<x-status-card label=\"$label\">\n\t\t\t$value\n\t\t</x-status-card>";
            }
        }, $content, -1, $count);

        if ($count > 0) {
            file_put_contents($file->getPathname(), $newContent);
            echo 'Updated '.basename($file->getPathname())." ($count replacements)\n";
        }
    }
}
