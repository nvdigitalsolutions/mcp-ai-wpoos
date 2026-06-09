/**
 * NV oOS Cloud — Subscription & Tenant Management
 *
 * Handles Stripe subscription webhooks and tenant CRUD operations.
 * Mounted under /v1/subscriptions and /v1/tenants.
 */

import { Hono } from 'hono';
import type { Env } from './types';
import { errorResponse } from './utils';
import Stripe from 'stripe';

const subscriptions = new Hono<{ Bindings: Env }>();

// ---------------------------------------------------------------------------
// Stripe webhook — handles subscription lifecycle events
// ---------------------------------------------------------------------------

subscriptions.post('/webhook', async (c) => {
  const rawBody = await c.req.text();
  const sig = c.req.header('Stripe-Signature');

  if (!sig) {
    return errorResponse(400, 'missing_signature', 'Stripe-Signature header is required.');
  }

  let event: Stripe.Event;
  try {
    const stripe = new Stripe(c.env.STRIPE_SECRET_KEY, { apiVersion: '2025-02-24.acacia' });
    event = await stripe.webhooks.constructEventAsync(
      rawBody,
      sig,
      c.env.STRIPE_WEBHOOK_SECRET,
      300 // 5-minute tolerance
    );
  } catch (err) {
    return errorResponse(401, 'invalid_signature', (err as Error).message);
  }

  // Idempotency: check if we've already processed this event
  const existing = await c.env.NVOOS_DB.prepare(
    'SELECT event_id FROM webhook_events WHERE event_id = ?1 LIMIT 1'
  ).bind(event.id).first();

  if (existing) {
    return Response.json({ received: true, idempotent: true });
  }

  // Record the event to prevent double-processing
  await c.env.NVOOS_DB.prepare(
    'INSERT INTO webhook_events (event_id, event_type, created_at) VALUES (?1, ?2, ?3)'
  ).bind(event.id, event.type, Math.floor(Date.now() / 1000)).run();

  // Route to the appropriate handler
  switch (event.type) {
    case 'checkout.session.completed':
      return handleCheckoutCompleted(c, event.data.object as Stripe.Checkout.Session);
    case 'invoice.paid':
      return handleInvoicePaid(c, event.data.object as Stripe.Invoice);
    case 'invoice.payment_failed':
      return handlePaymentFailed(c, event.data.object as Stripe.Invoice);
    case 'customer.subscription.deleted':
      return handleSubscriptionDeleted(c, event.data.object as Stripe.Subscription);
    case 'customer.subscription.updated':
      return handleSubscriptionUpdated(c, event.data.object as Stripe.Subscription);
    default:
      return Response.json({ received: true, type: event.type, action: 'ignored' });
  }
});

/**
 * Handle checkout.session.completed — provision a new tenant workspace.
 */
async function handleCheckoutCompleted(c: any, session: Stripe.Checkout.Session) {
  const metadata = session.metadata || {};
  const tenantSlug = metadata.tenant_slug;
  const tier = metadata.tier || 'starter';
  const adminEmail = session.customer_details?.email;
  const adminName = session.customer_details?.name || 'Admin';
  const customerId = session.customer as string;

  if (!tenantSlug || !adminEmail || !customerId) {
    console.error('Missing required metadata in checkout session:', session.id);
    return errorResponse(400, 'missing_metadata', 'tenant_slug, admin email, and customer ID are required.');
  }

  // Check if tenant already exists (idempotent)
  const existing = await c.env.NVOOS_DB.prepare(
    'SELECT id, status FROM tenants WHERE stripe_customer_id = ?1'
  ).bind(customerId).first();

  if (existing) {
    return Response.json({ ok: true, tenant_id: existing.id, status: existing.status, action: 'already_exists' });
  }

  const now = Math.floor(Date.now() / 1000);
  const tenantId = crypto.randomUUID();

  // Create tenant record
  await c.env.NVOOS_DB.prepare(`
    INSERT INTO tenants (id, slug, tier, stripe_customer_id, stripe_subscription_id, admin_email, status, created_at, updated_at)
    VALUES (?1, ?2, ?3, ?4, ?5, ?6, 'provisioning', ?7, ?7)
  `).bind(
    tenantId, tenantSlug, tier, customerId,
    session.subscription as string || '', adminEmail, now
  ).run();

  // Trigger WordPress provisioning
  const platformOrigin = c.env.PLATFORM_ORIGIN || 'https://scheduleanything.com';
  const saasApiKey = c.env.SAAS_API_KEY || '';

  try {
    const provisioningResult = await fetch(
      `${platformOrigin}/wp-json/nvoos-saas/v1/tenants/provision`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-SaaS-API-Key': saasApiKey,
        },
        body: JSON.stringify({
          slug: tenantSlug,
          tier,
          stripe_customer_id: customerId,
          admin_email: adminEmail,
          admin_name: adminName,
        }),
      }
    );

    if (!provisioningResult.ok) {
      const errorBody = await provisioningResult.text();
      console.error('Provisioning failed:', errorBody);

      // Mark tenant as failed provisioning
      await c.env.NVOOS_DB.prepare(
        "UPDATE tenants SET status = 'suspended', updated_at = ?1 WHERE id = ?2"
      ).bind(now, tenantId).run();

      return errorResponse(502, 'provisioning_failed', 'WordPress provisioning returned an error.');
    }

    const result = await provisioningResult.json() as any;
    const siteUrl = result.tenant?.site_url || '';

    // Update tenant with WP origin URL and set active
    await c.env.NVOOS_DB.prepare(
      "UPDATE tenants SET wp_origin_url = ?1, wp_blog_id = ?2, status = 'active', updated_at = ?3 WHERE id = ?4"
    ).bind(siteUrl, result.tenant?.blog_id || 0, now, tenantId).run();

    // Register in KV for tenant router
    if (c.env.TENANT_KV && siteUrl) {
      await c.env.TENANT_KV.put(tenantSlug, siteUrl);
    }

    return Response.json({
      ok: true,
      tenant: {
        id: tenantId,
        slug: tenantSlug,
        tier,
        site_url: siteUrl,
        login_url: result.tenant?.login_url || '',
      },
    });
  } catch (err) {
    console.error('Provisioning request failed:', err);
    return errorResponse(502, 'provisioning_error', 'Failed to reach WordPress provisioning endpoint.');
  }
}

/**
 * Handle invoice.paid — ensure tenant is active.
 */
async function handleInvoicePaid(c: any, invoice: Stripe.Invoice) {
  const customerId = invoice.customer as string;
  const now = Math.floor(Date.now() / 1000);

  await c.env.NVOOS_DB.prepare(
    "UPDATE tenants SET status = 'active', updated_at = ?1, stripe_subscription_id = ?2 WHERE stripe_customer_id = ?3"
  ).bind(now, invoice.subscription as string || '', customerId).run();

  return Response.json({ ok: true, action: 'tenant_activated' });
}

/**
 * Handle invoice.payment_failed — suspend the tenant.
 */
async function handlePaymentFailed(c: any, invoice: Stripe.Invoice) {
  const customerId = invoice.customer as string;
  const now = Math.floor(Date.now() / 1000);

  await c.env.NVOOS_DB.prepare(
    "UPDATE tenants SET status = 'suspended', updated_at = ?1 WHERE stripe_customer_id = ?2"
  ).bind(now, customerId).run();

  return Response.json({ ok: true, action: 'tenant_suspended' });
}

/**
 * Handle customer.subscription.deleted — offboard the tenant.
 */
async function handleSubscriptionDeleted(c: any, subscription: Stripe.Subscription) {
  const customerId = subscription.customer as string;

  const tenant = await c.env.NVOOS_DB.prepare(
    'SELECT id, slug, wp_origin_url, wp_blog_id FROM tenants WHERE stripe_customer_id = ?1'
  ).bind(customerId).first();

  if (!tenant) {
    return errorResponse(404, 'tenant_not_found', 'No tenant found for this Stripe customer.');
  }

  // Trigger WordPress offboarding (if blog exists)
  if (tenant.wp_blog_id) {
    const platformOrigin = c.env.PLATFORM_ORIGIN || 'https://scheduleanything.com';
    const saasApiKey = c.env.SAAS_API_KEY || '';

    try {
      await fetch(`${platformOrigin}/wp-json/nvoos-saas/v1/tenants/${tenant.wp_blog_id}/offboard`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-SaaS-API-Key': saasApiKey },
      });
    } catch (err) {
      console.error('Offboarding request failed:', err);
    }
  }

  // Remove from KV
  if (c.env.TENANT_KV) {
    await c.env.TENANT_KV.delete(tenant.slug as string);
  }

  const now = Math.floor(Date.now() / 1000);
  await c.env.NVOOS_DB.prepare(
    "UPDATE tenants SET status = 'cancelled', updated_at = ?1 WHERE id = ?2"
  ).bind(now, tenant.id).run();

  return Response.json({ ok: true, action: 'tenant_cancelled' });
}

/**
 * Handle customer.subscription.updated — track tier changes.
 */
async function handleSubscriptionUpdated(c: any, subscription: Stripe.Subscription) {
  const customerId = subscription.customer as string;
  const now = Math.floor(Date.now() / 1000);

  // Extract tier from the subscription's product metadata
  // This depends on how you configure Stripe Products
  const tier = subscription.items?.data?.[0]?.price?.metadata?.tier;

  if (tier) {
    await c.env.NVOOS_DB.prepare(
      "UPDATE tenants SET tier = ?1, updated_at = ?2 WHERE stripe_customer_id = ?3"
    ).bind(tier, now, customerId).run();
  }

  return Response.json({ ok: true, action: 'subscription_updated' });
}

// ---------------------------------------------------------------------------
// Tenant lookup — used by the tenant router for KV fallback
// ---------------------------------------------------------------------------

subscriptions.get('/lookup', async (c) => {
  const slug = c.req.query('slug');
  if (!slug) {
    return errorResponse(400, 'missing_slug', 'slug query parameter is required.');
  }

  const tenant = await c.env.NVOOS_DB.prepare(
    'SELECT slug, tier, wp_origin_url AS site_url, status FROM tenants WHERE slug = ?1'
  ).bind(slug).first();

  if (!tenant || tenant.status === 'cancelled') {
    return errorResponse(404, 'tenant_not_found', 'No active tenant found for this slug.');
  }

  return Response.json(tenant);
});

// ---------------------------------------------------------------------------
// Usage heartbeat receiver — accepts metrics from WP instances
// ---------------------------------------------------------------------------

subscriptions.post('/heartbeat', async (c) => {
  const apiKey = c.req.header('X-SaaS-API-Key');
  if (!apiKey || apiKey !== c.env.SAAS_API_KEY) {
    return errorResponse(401, 'unauthorized', 'Invalid or missing API key.');
  }

  const body = await c.req.json();
  const { blog_id, active_schedules, total_appointments, total_posts, storage_bytes_estimate, user_count } = body;

  if (!blog_id) {
    return errorResponse(400, 'missing_blog_id', 'blog_id is required.');
  }

  // Find tenant by blog_id
  const tenant = await c.env.NVOOS_DB.prepare(
    'SELECT id FROM tenants WHERE wp_blog_id = ?1 AND status = ?2'
  ).bind(blog_id, 'active').first();

  if (!tenant) {
    return errorResponse(404, 'tenant_not_found', 'No active tenant found for this blog ID.');
  }

  const today = new Date().toISOString().split('T')[0];
  const now = Math.floor(Date.now() / 1000);

  // Upsert usage record (one per tenant per day)
  await c.env.NVOOS_DB.prepare(`
    INSERT INTO tenant_usage (tenant_id, date, blog_id, active_schedules, total_appointments, total_posts, storage_bytes, user_count, reported_at)
    VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9)
    ON CONFLICT (tenant_id, date)
    DO UPDATE SET
      active_schedules = excluded.active_schedules,
      total_appointments = excluded.total_appointments,
      total_posts = excluded.total_posts,
      storage_bytes = excluded.storage_bytes,
      user_count = excluded.user_count,
      reported_at = excluded.reported_at
  `).bind(
    tenant.id, today, blog_id,
    active_schedules || 0,
    total_appointments || 0,
    total_posts || 0,
    storage_bytes_estimate || 0,
    user_count || 0,
    now
  ).run();

  return Response.json({ ok: true, tenant_id: tenant.id, date: today });
});

export default subscriptions;
