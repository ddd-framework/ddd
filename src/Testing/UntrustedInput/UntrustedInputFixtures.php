<?php

declare(strict_types=1);

namespace DDD\Testing\UntrustedInput;

/**
 * Bodies for tests of the untrusted-input rule: each would run an interpreter if it reached one.
 */
class UntrustedInputFixtures
{
    /** @var string[] Bodies that must never reach a decoder, by name */
    public const array INTERPRETER_BODIES = [
        'postscript' => "%!PS-Adobe-3.0\n/Helvetica findfont 12 scalefont setfont 10 10 moveto (owned) show showpage\n",
        'eps' => "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\nnewpath 0 0 moveto 10 10 lineto stroke\n",
        'pdf' => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script><image href="file:///etc/passwd"/></svg>',
        'mvg' => "push graphic-context\nviewbox 0 0 1 1\nimage over 0,0 1,1 'text:/etc/passwd'\npop graphic-context\n",
        'msl' => '<?xml version="1.0" encoding="UTF-8"?><image><read filename="/etc/passwd"/><write filename="/tmp/out.png"/></image>',
        'html' => '<html><body><script>alert(1)</script></body></html>',
    ];
}
