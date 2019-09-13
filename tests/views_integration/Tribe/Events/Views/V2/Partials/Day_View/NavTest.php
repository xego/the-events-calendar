<?php

namespace Tribe\Events\Views\V2\Partials\Day_View;

use Tribe\Test\Products\WPBrowser\Views\V2\HtmlPartialTestCase;

class NavTest extends HtmlPartialTestCase
{

	protected $partial_path = 'day/nav';

	/**
	 * Test render with all links
	 */
	public function test_render_with_all_links() {
		$this->assertMatchesSnapshot( $this->get_partial_html( [
			'prev_url'  => '#',
			'next_url'  => '#',
		] ) );
	}

	/**
	 * Test render without prev url
	 */
	public function test_render_without_prev_url() {
		$this->assertMatchesSnapshot( $this->get_partial_html( [
			'next_url'  => '#',
		] ) );
	}

	/**
	 * Test render without next url
	 */
	public function test_render_without_next_url() {
		$this->assertMatchesSnapshot( $this->get_partial_html( [
			'prev_url'  => '#',
		] ) );
	}

	/**
	 * Test render without prev and next url
	 */
	public function test_render_without_prev_and_next_url() {
		$this->assertMatchesSnapshot( $this->get_partial_html() );
	}
}
