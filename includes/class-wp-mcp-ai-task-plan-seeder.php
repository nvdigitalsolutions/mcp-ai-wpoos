<?php
/**
 * Task Plan Seeder
 *
 * Seeds example task plans into the database to demonstrate
 * the capabilities of various Pro toolkits.
 *
 * @package WP_MCP_AI
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds example task plans.
 */
class WP_MCP_AI_Task_Plan_Seeder {
	/**
	 * Option key to track if task plans have been seeded.
	 */
	const SEEDED_OPTION = 'wp_mcp_ai_task_plans_seeded';

	/**
	 * Initialize the seeder.
	 * Runs once on plugin activation or update.
	 */
	public static function init() {
		// Check if already seeded.
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}

		// Seed task plans on admin_init with high priority (after orchestration-init.php).
		add_action( 'admin_init', array( __CLASS__, 'seed_task_plans' ), 30 );
	}

	/**
	 * Seed example task plans.
	 */
	public static function seed_task_plans() {
		// Verify we haven't seeded yet.
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}

		// Get example task plans.
		$task_plans = self::get_example_task_plans();

		$created_count = 0;

		foreach ( $task_plans as $plan_data ) {
			// Create task plan using CPT.
			$post_id = wp_insert_post(
				array(
					'post_title'   => $plan_data['plan_name'],
					'post_content' => $plan_data['markdown'],
					'post_type'    => 'mcp_task_plan',
					'post_status'  => 'publish',
					'post_author'  => 1, // Admin user.
					'meta_input'   => array(
						'_goal'            => $plan_data['goal'],
						'_task_count'      => $plan_data['task_count'],
						'_completed_count' => 0,
						'_progress'        => 0,
						'_status'          => 'draft',
						'_project_id'      => 0,
						'_template_id'     => 0,
					),
				),
				true
			);

			if ( ! is_wp_error( $post_id ) ) {
				++$created_count;
			}
		}

		// Mark as seeded.
		update_option( self::SEEDED_OPTION, true, false );

		// Log successful seeding.
		if ( function_exists( 'wp_mcp_ai_log_activity' ) ) {
			wp_mcp_ai_log_activity(
				'task_plan_seeding',
				sprintf( 'Seeded %d example task plans', $created_count ),
				'system'
			);
		}
	}

	/**
	 * Get example task plans.
	 *
	 * @return array Array of task plan data.
	 */
	private static function get_example_task_plans() {
		return array(
			// 1. E-commerce Store Launch (E-commerce Toolkit).
			array(
				'plan_name'  => 'E-commerce Store Launch',
				'goal'       => 'Launch a professional e-commerce store from scratch, covering platform setup, product listings, payment integration, and marketing preparation.',
				'task_count' => 15,
				'markdown'   => self::generate_ecommerce_launch_plan(),
			),

			// 2. Social Media Campaign (Social Media Toolkit).
			array(
				'plan_name'  => 'Social Media Marketing Campaign',
				'goal'       => 'Execute a comprehensive social media marketing campaign across multiple platforms with scheduled content, engagement tracking, and analytics.',
				'task_count' => 12,
				'markdown'   => self::generate_social_media_campaign_plan(),
			),

			// 3. Financial Portfolio Analysis (Financial Planner Toolkit).
			array(
				'plan_name'  => 'Client Financial Portfolio Analysis',
				'goal'       => 'Conduct a comprehensive financial portfolio analysis for a client, including risk assessment, retirement planning, and investment recommendations.',
				'task_count' => 14,
				'markdown'   => self::generate_financial_portfolio_plan(),
			),

			// 4. Website Analytics Audit (Analytics Toolkit).
			array(
				'plan_name'  => 'Website Analytics & Performance Audit',
				'goal'       => 'Perform a complete analytics audit of website performance, user behavior, conversion funnels, and generate actionable insights for optimization.',
				'task_count' => 11,
				'markdown'   => self::generate_analytics_audit_plan(),
			),

			// 5. Video Content Production (Video Production Toolkit).
			array(
				'plan_name'  => 'Video Marketing Series Production',
				'goal'       => 'Plan, produce, and distribute a professional video marketing series including scripting, filming, editing, optimization, and multi-platform publishing.',
				'task_count' => 13,
				'markdown'   => self::generate_video_production_plan(),
			),

			// 6. Multilingual Website Expansion (Multilingual Toolkit).
			array(
				'plan_name'  => 'Multilingual Website Expansion',
				'goal'       => 'Expand website to support multiple languages with professional translation, localization, SEO optimization, and cultural adaptation.',
				'task_count' => 10,
				'markdown'   => self::generate_multilingual_expansion_plan(),
			),

			// 7. Content Marketing Strategy (Media Toolkit + Social Media).
			array(
				'plan_name'  => 'Content Marketing Strategy Launch',
				'goal'       => 'Develop and execute a complete content marketing strategy including blog series, social media distribution, SEO optimization, and performance tracking.',
				'task_count' => 14,
				'markdown'   => self::generate_content_marketing_plan(),
			),

			// 8. Product Photography & Image Optimization (Image Production Toolkit).
			array(
				'plan_name'  => 'Product Photography & Image Optimization',
				'goal'       => 'Create professional product photography workflow with batch processing, background removal, optimization, and consistent branding across all product images.',
				'task_count' => 9,
				'markdown'   => self::generate_image_optimization_plan(),
			),
		);
	}

	/**
	 * Generate E-commerce Store Launch plan markdown.
	 *
	 * @return string
	 */
	private static function generate_ecommerce_launch_plan() {
		return "# E-commerce Store Launch

## Goal
Launch a professional e-commerce store from scratch, covering platform setup, product listings, payment integration, and marketing preparation.

## Tasks

### Foundation Setup
- [ ] Select and configure e-commerce platform (WooCommerce) (Priority: High)
- [ ] Register custom domain and configure DNS settings (Priority: High)
- [ ] Install SSL certificate and configure HTTPS (Priority: High)
- [ ] Set up business entity and tax settings (Priority: High)

### Store Configuration
- [ ] Choose and customize store theme for branding (Priority: Medium)
- [ ] Create essential pages (About, Contact, Privacy Policy, Terms, Shipping, Returns) (Priority: High)
- [ ] Configure shipping zones, rates, and carrier integrations (Priority: High)
- [ ] Set up payment gateways (Stripe, PayPal, etc.) (Priority: High)

### Product Management
- [ ] Add product listings with descriptions, pricing, and high-quality images (Priority: High)
- [ ] Organize products into categories and tags (Priority: Medium)
- [ ] Configure inventory tracking and low-stock alerts (Priority: Medium)

### Marketing & Analytics
- [ ] Install and configure Google Analytics and tracking pixels (Priority: High)
- [ ] Set up email automation (welcome, abandoned cart, order confirmation) (Priority: Medium)
- [ ] Optimize product pages for SEO (meta titles, descriptions, alt text) (Priority: Medium)

### Pre-Launch Testing
- [ ] Test complete checkout flow with test orders (Priority: High)
- [ ] Verify mobile responsiveness and page load speeds (Priority: High)

## Status
Progress: 0%
Created: " . current_time( 'Y-m-d H:i:s' ) . '
Toolkit: E-commerce
';
	}

	/**
	 * Generate Social Media Marketing Campaign plan markdown.
	 *
	 * @return string
	 */
	private static function generate_social_media_campaign_plan() {
		return "# Social Media Marketing Campaign

## Goal
Execute a comprehensive social media marketing campaign across multiple platforms with scheduled content, engagement tracking, and analytics.

## Tasks

### Campaign Planning
- [ ] Define campaign goals, KPIs, and target audience segments (Priority: High)
- [ ] Research competitors and conduct content audit (Priority: Medium)
- [ ] Select primary platforms (Facebook, Instagram, LinkedIn, Twitter) (Priority: High)
- [ ] Create campaign timeline and content calendar (Priority: High)

### Content Creation
- [ ] Develop campaign messaging and brand voice guidelines (Priority: High)
- [ ] Design graphics, images, and video content for all platforms (Priority: High)
- [ ] Write compelling post copy and captions with CTAs (Priority: Medium)
- [ ] Prepare landing pages and tracking URLs (Priority: Medium)

### Campaign Execution
- [ ] Schedule content across all platforms using automation tools (Priority: High)
- [ ] Set up campaign tracking pixels and UTM parameters (Priority: High)
- [ ] Launch campaign and monitor initial performance (Priority: High)

### Monitoring & Optimization
- [ ] Monitor engagement, reach, and conversion metrics daily (Priority: Medium)
- [ ] Respond to comments and messages promptly (Priority: Medium)
- [ ] Adjust content strategy based on performance data (Priority: Medium)
- [ ] Generate campaign performance report with insights and recommendations (Priority: High)

## Status
Progress: 0%
Created: " . current_time( 'Y-m-d H:i:s' ) . '
Toolkit: Social Media
';
	}

	/**
	 * Generate Financial Portfolio Analysis plan markdown.
	 *
	 * @return string
	 */
	private static function generate_financial_portfolio_plan() {
		return "# Client Financial Portfolio Analysis

## Goal
Conduct a comprehensive financial portfolio analysis for a client, including risk assessment, retirement planning, and investment recommendations.

## Tasks

### Client Discovery
- [ ] Schedule initial consultation and gather client information (Priority: High)
- [ ] Collect financial documents (income, expenses, assets, liabilities) (Priority: High)
- [ ] Document client goals, risk tolerance, and time horizons (Priority: High)

### Current Situation Analysis
- [ ] Analyze current financial position and cash flow (Priority: High)
- [ ] Review existing investments and asset allocation (Priority: High)
- [ ] Evaluate insurance coverage and risk management (Priority: Medium)
- [ ] Assess debt obligations and payment schedules (Priority: Medium)

### Planning & Strategy
- [ ] Develop retirement income projections and savings targets (Priority: High)
- [ ] Create tax-efficient investment strategy (Priority: High)
- [ ] Recommend portfolio rebalancing and diversification (Priority: High)
- [ ] Identify estate planning considerations (Priority: Medium)

### Presentation & Implementation
- [ ] Prepare comprehensive financial plan presentation (Priority: High)
- [ ] Review recommendations with client and address questions (Priority: High)
- [ ] Assist with account setup and investment transfers (Priority: Medium)
- [ ] Schedule quarterly review meetings for ongoing monitoring (Priority: Medium)

## Status
Progress: 0%
Created: " . current_time( 'Y-m-d H:i:s' ) . '
Toolkit: Financial Planner
';
	}

	/**
	 * Generate Website Analytics Audit plan markdown.
	 *
	 * @return string
	 */
	private static function generate_analytics_audit_plan() {
		return "# Website Analytics & Performance Audit

## Goal
Perform a complete analytics audit of website performance, user behavior, conversion funnels, and generate actionable insights for optimization.

## Tasks

### Setup & Data Collection
- [ ] Audit existing analytics tracking implementation (Priority: High)
- [ ] Set up enhanced tracking (events, conversions, custom dimensions) (Priority: High)
- [ ] Verify data accuracy and configure filters (Priority: High)

### Traffic Analysis
- [ ] Analyze traffic sources, channels, and acquisition patterns (Priority: High)
- [ ] Identify top-performing pages and content (Priority: Medium)
- [ ] Review user demographics, interests, and device usage (Priority: Medium)

### Behavior & Engagement
- [ ] Map user flows and navigation patterns (Priority: Medium)
- [ ] Analyze bounce rates and exit pages (Priority: Medium)
- [ ] Review on-site search behavior and queries (Priority: Low)

### Conversion Analysis
- [ ] Set up and analyze conversion funnels (Priority: High)
- [ ] Identify conversion bottlenecks and drop-off points (Priority: High)
- [ ] Calculate ROI by channel and campaign (Priority: Medium)

### Reporting & Recommendations
- [ ] Create comprehensive audit report with visualizations (Priority: High)
- [ ] Provide prioritized recommendations for optimization (Priority: High)

## Status
Progress: 0%
Created: " . current_time( 'Y-m-d H:i:s' ) . '
Toolkit: Analytics
';
	}

	/**
	 * Generate Video Marketing Production plan markdown.
	 *
	 * @return string
	 */
	private static function generate_video_production_plan() {
		return "# Video Marketing Series Production

## Goal
Plan, produce, and distribute a professional video marketing series including scripting, filming, editing, optimization, and multi-platform publishing.

## Tasks

### Pre-Production Planning
- [ ] Define video series theme, target audience, and goals (Priority: High)
- [ ] Develop video scripts and storyboards (Priority: High)
- [ ] Plan shooting schedule, locations, and equipment needs (Priority: High)

### Production
- [ ] Set up filming equipment and lighting (Priority: High)
- [ ] Record video content following scripts and storyboards (Priority: High)
- [ ] Capture B-roll footage and additional assets (Priority: Medium)

### Post-Production
- [ ] Edit videos with cuts, transitions, and effects (Priority: High)
- [ ] Add music, sound effects, and voiceover narration (Priority: Medium)
- [ ] Create captions and subtitles for accessibility (Priority: Medium)
- [ ] Optimize video files for web (compression, formats) (Priority: High)

### Distribution & Promotion
- [ ] Upload videos to YouTube, Vimeo, and social platforms (Priority: High)
- [ ] Optimize video metadata (titles, descriptions, tags, thumbnails) (Priority: High)
- [ ] Create promotional social media posts and email announcements (Priority: Medium)
- [ ] Monitor video analytics and engagement metrics (Priority: Medium)

## Status
Progress: 0%
Created: " . current_time( 'Y-m-d H:i:s' ) . '
Toolkit: Video Production
';
	}

	/**
	 * Generate Multilingual Website Expansion plan markdown.
	 *
	 * @return string
	 */
	private static function generate_multilingual_expansion_plan() {
		return "# Multilingual Website Expansion

## Goal
Expand website to support multiple languages with professional translation, localization, SEO optimization, and cultural adaptation.

## Tasks

### Planning & Setup
- [ ] Identify target languages and markets based on analytics (Priority: High)
- [ ] Configure multilingual plugin and URL structure (Priority: High)
- [ ] Create translation workflow and glossary (Priority: High)

### Content Translation
- [ ] Translate core pages (Home, About, Contact, Services) (Priority: High)
- [ ] Translate product/service descriptions and pricing (Priority: High)
- [ ] Localize images, graphics, and visual content (Priority: Medium)
- [ ] Adapt content for cultural relevance and local regulations (Priority: Medium)

### Technical Implementation
- [ ] Set up hreflang tags for proper indexing (Priority: High)
- [ ] Configure language switcher and user preferences (Priority: High)

### SEO & Marketing
- [ ] Research and implement keywords in target languages (Priority: Medium)
- [ ] Create localized meta titles and descriptions (Priority: Medium)
- [ ] Test functionality across all language versions (Priority: High)

## Status
Progress: 0%
Created: " . current_time( 'Y-m-d H:i:s' ) . '
Toolkit: Multilingual
';
	}

	/**
	 * Generate Content Marketing Strategy plan markdown.
	 *
	 * @return string
	 */
	private static function generate_content_marketing_plan() {
		return "# Content Marketing Strategy Launch

## Goal
Develop and execute a complete content marketing strategy including blog series, social media distribution, SEO optimization, and performance tracking.

## Tasks

### Strategy Development
- [ ] Define content marketing goals and target audience personas (Priority: High)
- [ ] Conduct keyword research and competitive content analysis (Priority: High)
- [ ] Create content calendar for 3 months (Priority: High)
- [ ] Establish content themes and editorial guidelines (Priority: Medium)

### Content Creation
- [ ] Write pillar content pieces (comprehensive guides) (Priority: High)
- [ ] Create supporting blog posts and articles (Priority: High)
- [ ] Design infographics and visual content assets (Priority: Medium)
- [ ] Develop lead magnets (eBooks, whitepapers, templates) (Priority: Medium)

### SEO Optimization
- [ ] Optimize all content with target keywords (Priority: High)
- [ ] Add internal linking structure (Priority: Medium)
- [ ] Create meta descriptions and optimize images (Priority: Medium)

### Distribution & Promotion
- [ ] Publish content according to calendar (Priority: High)
- [ ] Share on social media with custom copy for each platform (Priority: High)
- [ ] Set up email newsletter distribution (Priority: Medium)

### Analytics & Iteration
- [ ] Track content performance metrics (traffic, engagement, conversions) (Priority: High)
- [ ] Generate monthly content performance report (Priority: High)
- [ ] Adjust strategy based on data insights (Priority: Medium)

## Status
Progress: 0%
Created: " . current_time( 'Y-m-d H:i:s' ) . '
Toolkit: Media + Social Media
';
	}

	/**
	 * Generate Product Photography & Image Optimization plan markdown.
	 *
	 * @return string
	 */
	private static function generate_image_optimization_plan() {
		return "# Product Photography & Image Optimization

## Goal
Create professional product photography workflow with batch processing, background removal, optimization, and consistent branding across all product images.

## Tasks

### Setup & Planning
- [ ] Set up photography equipment and lighting studio (Priority: High)
- [ ] Create shot list and style guide for consistency (Priority: High)
- [ ] Prepare products for photography (cleaning, staging) (Priority: Medium)

### Photography Session
- [ ] Photograph all products with multiple angles (Priority: High)
- [ ] Capture detail shots and lifestyle images (Priority: Medium)
- [ ] Review and select best shots from each session (Priority: Medium)

### Post-Production
- [ ] Remove backgrounds using AI tools (Priority: High)
- [ ] Color correct and enhance images (Priority: High)
- [ ] Resize and optimize images for web (Priority: High)
- [ ] Add watermarks or branding elements (Priority: Low)

### Implementation
- [ ] Upload optimized images to product database (Priority: High)
- [ ] Update product pages with new photography (Priority: High)

## Status
Progress: 0%
Created: " . current_time( 'Y-m-d H:i:s' ) . '
Toolkit: Image Production
';
	}
}
