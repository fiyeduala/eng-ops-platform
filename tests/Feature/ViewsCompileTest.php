<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every Blade template in the platform compiles to valid PHP.
 *
 * THIS EXISTS BECAUSE `php artisan view:cache` DOES NOT CATCH THIS. Caching compiles Blade to PHP
 * and writes the result to disk; it never executes it, so a template that compiles to a syntax
 * error caches successfully and fails at the moment a person opens the page. Until this file was
 * written the only thing standing between a broken template and a production 500 was whether some
 * feature test happened to render that particular screen — and most screens have no such test.
 *
 * Two failures are checked, both found in already-committed views:
 *
 *   A DIRECTIVE GLUED TO THE WORD BEFORE IT IS NOT A DIRECTIVE. Blade's pattern requires a
 *   non-word character in front of the `@`, so `...the promised date@endif` emits the literal
 *   text "@endif" and leaves the `@if` open to the end of the file. What the reader sees is a
 *   page with "@endif" printed in the middle of a sentence, or a fatal parse error, depending on
 *   what follows. Three of these had shipped.
 *
 *   `@json` SPLITS ITS EXPRESSION ON COMMAS. `@json(['a' => 1, 'b' => 2])` compiles to
 *   `json_encode($parts[0], $parts[1], $parts[2])` — the array is cut at the first comma and the
 *   remainder is read as the flags and depth arguments. A multi-element array literal can never
 *   be passed to it; the expression has to be one term, which in practice means a variable built
 *   in an `@php` block above.
 *
 * Both are compile-time properties of a template, which is why this is one sweep over the whole
 * directory rather than a test per screen. It is deliberately not a rendering test: rendering
 * needs data, and a test that needed a fixture per view is a test nobody would keep passing.
 */
final class ViewsCompileTest extends TestCase
{
    #[Test]
    public function every_template_compiles_to_valid_php(): void
    {
        $broken = [];

        foreach ($this->templates() as $path) {
            $compiled = Blade::compileString(file_get_contents($path));

            try {
                // In process, and fast enough for 470-odd templates. TOKEN_PARSE makes the
                // tokeniser raise on anything the compiler itself would reject, which is the same
                // check `php -l` performs without paying for a process per file.
                token_get_all($compiled, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $broken[] = $this->relative($path).' — '.$e->getMessage();
            }
        }

        $this->assertSame([], $broken, "These templates compile to PHP that will not parse:\n".implode("\n", $broken));
    }

    #[Test]
    public function no_directive_is_glued_to_the_word_before_it(): void
    {
        $glued = [];

        foreach ($this->templates() as $path) {
            // Blade comments are stripped first: the fix for one of these bugs is documented in a
            // comment that necessarily quotes the broken form, and a linter that cannot tell a
            // warning from the thing it warns about is a linter people switch off.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));

            $directives = 'if|unless|foreach|forelse|for|while|php|json|switch|isset|empty|auth|guest|can|cannot|error|section|push';

            if (preg_match_all('/\w@(?:end)?(?:'.$directives.')\b/', $source, $matches)) {
                foreach ($matches[0] as $hit) {
                    $glued[] = $this->relative($path).' — '.$hit;
                }
            }
        }

        $this->assertSame([], $glued, "A directive preceded by a word character is emitted as literal text, not compiled:\n".implode("\n", $glued));
    }

    #[Test]
    public function no_json_directive_is_handed_more_than_one_term(): void
    {
        $risky = [];

        foreach ($this->templates() as $path) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));

            foreach ($this->jsonArguments($source) as $expression) {
                // Blade's compileJson is literally `explode(',', $expression)`, taking [1] as the
                // flags and [2] as the depth. So a comma ANYWHERE — inside an array literal, a
                // nested call, even a quoted string — truncates the value and turns the rest into
                // arguments. Depth of nesting is irrelevant, which is why this is a plain search
                // and not a top-level-comma one.
                if (str_contains($expression, ',')) {
                    $risky[] = $this->relative($path).' — @json('.trim($expression).')';
                }
            }
        }

        $this->assertSame([], $risky, "@json splits on commas, so all but the first term is dropped. Build the value in @php and pass one variable:\n".implode("\n", $risky));
    }

    /**
     * The expression inside each `@json(...)` in a template.
     *
     * Scanned by counting parentheses rather than matched with a regex, because the argument
     * routinely contains parentheses of its own and a non-greedy `.*?` stops at the first `)` it
     * finds — which for an Alpine `x-data` block means capturing half the component.
     *
     * @return array<int,string>
     */
    private function jsonArguments(string $source): array
    {
        $found = [];
        $offset = 0;

        while (($at = strpos($source, '@json', $offset)) !== false) {
            $open = strpos($source, '(', $at);
            $offset = $at + 5;

            // `@json` with no parentheses after it is not a call to worry about.
            if ($open === false || trim(substr($source, $at + 5, $open - $at - 5)) !== '') {
                continue;
            }

            $depth = 0;

            for ($i = $open; $i < strlen($source); $i++) {
                $depth += ($source[$i] === '(' ? 1 : ($source[$i] === ')' ? -1 : 0));

                if ($depth === 0) {
                    $found[] = substr($source, $open + 1, $i - $open - 1);
                    $offset = $i;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<int,string>
     */
    private function templates(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        $this->assertNotEmpty($found, 'No templates were found, so this sweep proved nothing.');

        return $found;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
