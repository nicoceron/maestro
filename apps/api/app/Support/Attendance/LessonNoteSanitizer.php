<?php

namespace App\Support\Attendance;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class LessonNoteSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $this->sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->dropElement('img')
                ->dropElement('audio')
                ->dropElement('video')
                ->dropElement('source')
                ->dropElement('iframe')
                ->allowLinkSchemes(['https', 'mailto', 'tel'])
                ->forceAttribute('a', 'rel', 'noopener noreferrer')
                ->forceHttpsUrls(),
        );
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
