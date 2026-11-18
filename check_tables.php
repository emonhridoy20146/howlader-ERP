<?php

$directory = __DIR__.'/resources/views';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
$filesWithTable = [];

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        if (strpos($content, '<table') !== false) {
            $filesWithTable[] = $file->getPathname();
        }
    }
}
echo 'Files with table: '.count($filesWithTable)."\n";
foreach ($filesWithTable as $file) {
    echo basename($file)."\n";
}
