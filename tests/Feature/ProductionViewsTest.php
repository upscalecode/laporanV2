<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionViewsTest extends TestCase
{
    public function test_shared_layout_activates_only_the_requested_view(): void
    {
        foreach (['index' => 'dashboard', 'spk' => 'spk', 'filling' => 'filling', 'press' => 'press', 'apd' => 'apd', 'laporan' => 'laporan', 'setting' => 'master'] as $page => $active) {
            $html = $this->get('/'.$page.'.html')->assertOk()->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML($html);
            $xpath = new \DOMXPath($document);
            $visible = $xpath->query('//section[starts-with(@id, "view-") and not(@hidden)]');
            $this->assertCount(1, $visible, $page);
            $this->assertSame('view-'.$active, $visible->item(0)->getAttribute('id'));
            $buttons = $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " tab-btn ") and contains(concat(" ", normalize-space(@class), " "), " active ")]');
            $this->assertCount(1, $buttons, $page);
            $this->assertSame($active, $buttons->item(0)->getAttribute('data-view'));
            $this->assertCount(1, $xpath->query('//script[contains(@src, "/js/app.js")]'));
            $this->assertCount(0, $xpath->query('//style | //script[not(@src)]'));
        }
    }

    public function test_login_loads_the_shared_script_once(): void
    {
        $html = $this->get('/login.html')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '/js/app.js'));
        $this->assertStringNotContainsString('xlsx.full.min.js', $html);
    }
}
