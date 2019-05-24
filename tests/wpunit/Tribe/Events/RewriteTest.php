<?php

namespace Tribe\Events;

use Tribe__Events__Rewrite as Rewrite;

if ( ! class_exists( '\\SitePress' ) ) {
	require_once codecept_data_dir( 'classes/SitePress.php' );
}

class RewriteTest extends \Codeception\TestCase\WPTestCase {

	/**
	 * @var \WP_Rewrite
	 */
	protected $wp_rewrite;

	public function setUp() {
		// before
		parent::setUp();

		// your set up methods here
		$this->wp_rewrite = $this->prophesize( 'WP_Rewrite' );
	}

	public function tearDown() {
		// your tear down methods here

		// then
		parent::tearDown();
	}

	/**
	 * @test
	 * it should be instantiatable
	 */
	public function it_should_be_instantiatable() {
		$sut = $this->make_instance();

		$this->assertInstanceOf( 'Tribe__Events__Rewrite', $sut );
	}

	/**
	 * @test
	 * it should filter post type link for supported post types only
	 */
	public function it_should_filter_post_type_link_for_supported_post_types_only() {
		$post = $this->factory()->post->create_and_get();

		$sut = $this->make_instance();

		$this->assertEquals( 'foo', $sut->filter_post_type_link( 'foo', $post ) );
	}

	private function make_instance() {
		return new Rewrite( $this->wp_rewrite->reveal() );
	}

	public function canonical_urls() {
		return [
			'not_ours'                => [
				'/?post_type=post&foo=bar',
				'/?post_type=post&foo=bar',
			],
			'list_page_1'             => [
				'/?post_type=tribe_events&eventDisplay=list',
				'/events/list/',
			],
			'list_page_2'             => [
				'/?post_type=tribe_events&eventDisplay=list&paged=2',
				'/events/list/page/2/',
			],
			'list_page_1_w_extra'     => [
				'/?post_type=tribe_events&eventDisplay=list&foo=bar',
				'/events/list/?foo=bar',
			],
			'tag_page_1'              => [
				'/?post_type=tribe_events&eventDisplay=list&tag=test',
				'/events/tag/test/list/',
			],
			'tag_page_1_w_extra'      => [
				'/?post_type=tribe_events&eventDisplay=list&tag=test&foo=bar',
				'/events/tag/test/list/?foo=bar',
			],
			'tag_page_2'              => [
				'/?post_type=tribe_events&eventDisplay=list&tag=test&paged=2',
				'/events/tag/test/list/page/2/',
			],
			'tag_page_2_w_extra'      => [
				'/?post_type=tribe_events&eventDisplay=list&tag=test&paged=2&foo=bar',
				'/events/tag/test/list/page/2/?foo=bar',
			],
			'category_page_1'         => [
				'/?post_type=tribe_events&eventDisplay=list&tribe_events_cat=test',
				'/events/category/test/list/',
			],
			'category_page_1_w_extra' => [
				'/?post_type=tribe_events&eventDisplay=list&tribe_events_cat=test&foo=bar',
				'/events/category/test/list/?foo=bar',
			],
			'category_page_2'         => [
				'/?post_type=tribe_events&eventDisplay=list&tribe_events_cat=test&paged=2',
				'/events/category/test/list/page/2/',
			],
			'category_page_2_w_extra' => [
				'/?post_type=tribe_events&eventDisplay=list&tribe_events_cat=test&paged=2&foo=bar',
				'/events/category/test/list/page/2/?foo=bar',
			],
			'day_page'                => [
				'/?post_type=tribe_events&eventDisplay=day&eventDate=2018-12-01',
				'/events/2018-12-01/',
			],
			'month_page'              => [
				'/?post_type=tribe_events&eventDisplay=month&eventDate=2018-12',
				'/events/2018-12/',
			],
			'feed_page'               => [
				'/?post_type=tribe_events&tag=test&feed=rss2',
				'/events/tag/test/feed/rss2/',
			],
			'ical_page'               => [
				'/?post_type=tribe_events&tag=test&ical=1',
				'/events/tag/test/ical/',
			],
		];
	}

	/**
	 * It should allow converting a URL to its canonical form
	 *
	 * @test
	 * @dataProvider canonical_urls
	 */
	public function should_allow_converting_a_url_to_its_canonical_form( $uri, $expected ) {
		global $wp_rewrite;
		$wp_rewrite->permalink_structure = '/%postname%/';
		$wp_rewrite->rewrite_rules();
		$canonical_url = ( new Rewrite )->get_canonical_url( home_url( $uri ) );

		$this->assertEquals( home_url( $expected ), $canonical_url );
	}
}
