<?php

namespace Tests\Feature\BugHunt\Concerns;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Testing\TestResponse;

/**
 * Submit a rendered <form> the way a browser does, so a repro test does not depend on
 * which of "controller default" or "hidden fallback input" the fix picks.
 *
 * A browser posts every successful control in the form: text/number/date/hidden inputs,
 * textareas, the selected option of each select, and a checkbox or radio ONLY when it is
 * checked. An unchecked checkbox sends nothing. That last rule is the whole point of the
 * "can't turn it off" bug class.
 */
trait SubmitsRenderedForms
{
    /**
     * Fields a browser would send for the form whose action is $action.
     *
     * @param  array<int,string>  $uncheck  names of checkboxes the user un-ticks
     * @param  array<string,string>  $set  field values the user types / picks (replaces what was rendered)
     * @return array<string,mixed>  nested array, as PHP parses a query string
     */
    protected function browserPayload(string $html, string $action, array $uncheck = [], array $set = []): array
    {
        $pairs = [];
        foreach ($this->successfulControls($html, $action, $uncheck) as [$name, $value]) {
            if (array_key_exists($name, $set)) {
                continue;
            }
            $pairs[] = [$name, $value];
        }
        foreach ($set as $name => $value) {
            $pairs[] = [$name, (string) $value];
        }

        $query = implode('&', array_map(fn ($p) => rawurlencode($p[0]).'='.rawurlencode($p[1]), $pairs));
        parse_str($query, $payload);

        return $payload;
    }

    /** Submit the rendered form for $action as the browser would, honouring a spoofed _method. */
    protected function submitRenderedForm(string $html, string $action, array $uncheck = [], array $set = []): TestResponse
    {
        $payload = $this->browserPayload($html, $action, $uncheck, $set);
        $method = strtoupper($payload['_method'] ?? 'POST');

        return $this->call($method, $action, $payload);
    }

    /** @return array<int,array{0:string,1:string}> */
    private function successfulControls(string $html, string $action, array $uncheck): array
    {
        if (! preg_match('~<form\b[^>]*\baction="'.preg_quote($action, '~').'"[^>]*>~', $html, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail("No <form action=\"{$action}\"> in the page.");
        }
        $start = $m[0][1];
        $end = strpos($html, '</form>', $start);
        $fragment = substr($html, $start, ($end === false ? strlen($html) : $end) - $start);

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div>'.$fragment.'</div>');
        libxml_clear_errors();

        $out = [];
        foreach ((new DOMXPath($dom))->query('//input|//select|//textarea') as $el) {
            /** @var DOMElement $el */
            $name = $el->getAttribute('name');
            // `:disabled="true"` is Alpine's static form of `disabled`: it is applied on load, before any submit.
            if ($name === '' || $el->hasAttribute('disabled') || trim($el->getAttribute(':disabled')) === 'true') {
                continue;
            }

            $tag = $el->tagName;
            if ($tag === 'textarea') {
                $out[] = [$name, $el->textContent];
            } elseif ($tag === 'select') {
                $value = null;
                foreach ($el->getElementsByTagName('option') as $opt) {
                    $v = $opt->hasAttribute('value') ? $opt->getAttribute('value') : $opt->textContent;
                    $value ??= $v; // browsers fall back to the first option
                    if ($opt->hasAttribute('selected')) {
                        $value = $v;
                    }
                }
                $out[] = [$name, (string) $value];
            } else {
                $type = strtolower($el->getAttribute('type') ?: 'text');
                if (in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                    continue;
                }
                if (in_array($type, ['checkbox', 'radio'], true)) {
                    if (! $el->hasAttribute('checked') || in_array($name, $uncheck, true)) {
                        continue; // an unchecked box sends nothing
                    }
                    $out[] = [$name, $el->hasAttribute('value') ? $el->getAttribute('value') : 'on'];

                    continue;
                }
                $out[] = [$name, $el->getAttribute('value')];
            }
        }

        return $out;
    }
}
