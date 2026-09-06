<?php

namespace pbFenom;

/**
 * The documentation used to describe a tag that did not exist, link to a two-major-old
 * upstream tag and point at pages that had been deleted. These keep it honest.
 */
class DocsTest extends TestCase
{
    private static function docs(): string
    {
        return dirname(__DIR__, 3) . '/docs';
    }

    /**
     * @return \SplFileInfo[]
     */
    private static function pages(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(
                     new \RecursiveDirectoryIterator(self::docs())) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'md') {
                $out[] = $file;
            }
        }
        return $out;
    }

    public function testNoBrokenInternalLinks()
    {
        $broken = [];
        foreach (self::pages() as $file) {
            $body = file_get_contents($file->getPathname());
            preg_match_all('~\[([^\]]*)\]\((?!https?:|#)([^)#]+)(?:#[^)]*)?\)~', $body, $m, PREG_SET_ORDER);
            foreach ($m as [, $label, $target]) {
                if (!file_exists($file->getPath() . '/' . $target)) {
                    $broken[] = $file->getFilename() . ": [$label]($target)";
                }
            }
        }
        $this->assertSame([], $broken);
    }

    /**
     * {autotrim} and the :trim/:ltrim/:rtrim tag options were documented for a decade
     * and never implemented.
     */
    public function testNoDocsForUnimplementedFeatures()
    {
        $ghosts = [];
        foreach (self::pages() as $file) {
            // the upstream-diff pages exist to say what was removed, so they name
            // removed APIs on purpose
            if ($file->getFilename() === 'upstream-diff.md') {
                continue;
            }
            $body = file_get_contents($file->getPathname());
            foreach (['autotrim', 'registerAutoload', 'AUTO_TRIM', 'auto_trim'] as $ghost) {
                if (str_contains($body, $ghost)) {
                    $ghosts[] = $file->getFilename() . ": $ghost";
                }
            }
        }
        $this->assertSame([], $ghosts);
    }

    /**
     * Every tag and modifier the docs advertise must actually be registered.
     */
    public function testIndexedModifiersExist()
    {
        $missing = [];
        foreach (['en', 'ru'] as $lang) {
            $index = file_get_contents(self::docs() . "/$lang/readme.md");
            preg_match_all('~\[(\w+)\]\(\./mods/[\w_]+\.md\)~', $index, $m);
            foreach ($m[1] as $name) {
                if ($this->fenom->getModifier($name) === null) {
                    $missing[] = "$lang: $name";
                }
            }
        }
        $this->assertSame([], $missing, 'the index links modifiers that are not registered');
    }

    /**
     * Links pinned to bzick/fenom@1.2.2 pointed at files that had since moved.
     */
    public function testNoLinksToStaleUpstreamRevisions()
    {
        $stale = [];
        foreach (self::pages() as $file) {
            if (str_contains(file_get_contents($file->getPathname()), 'blob/1.2.2')) {
                $stale[] = $file->getFilename();
            }
        }
        $this->assertSame([], $stale);
    }
}
