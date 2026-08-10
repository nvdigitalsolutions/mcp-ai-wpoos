import { Router } from 'express';
import axios from 'axios';
import OpenAI from 'openai';

const router = Router();

// ── Social Media Platforms Configuration ──────────────────
const platforms = {
  twitter: {
    name: 'Twitter / X',
    api: 'https://api.twitter.com/2/tweets',
    requires: ['TWITTER_ACCESS_TOKEN'],
    maxChars: 280,
  },
  facebook: {
    name: 'Facebook',
    api: 'https://graph.facebook.com/v19.0',
    requires: ['FACEBOOK_PAGE_TOKEN'],
    maxChars: 63206,
  },
  instagram: {
    name: 'Instagram',
    api: 'https://graph.instagram.com/v21.0',
    requires: ['INSTAGRAM_ACCESS_TOKEN'],
    maxChars: 2200,
    mediaRequired: true,
  },
  linkedin: {
    name: 'LinkedIn',
    api: 'https://api.linkedin.com/v2',
    requires: ['LINKEDIN_TOKEN'],
    maxChars: 3000,
  },
};

// ── Get Connected Platforms ───────────────────────────────
router.get('/accounts', (_req, res) => {
  const connected = Object.entries(platforms).map(([key, plat]) => ({
    id: key,
    name: plat.name,
    connected: plat.requires.every((env) => !!process.env[env]),
    max_chars: plat.maxChars,
  }));

  res.json({ platforms: connected });
});

// ── Post to Social Media ──────────────────────────────────
router.post('/post', async (req, res) => {
  try {
    const { platform, content, media_url, schedule, hashtags, media_type = 'image' } = req.body;

    if (!platform || !content) {
      return res.status(400).json({ error: 'Platform and content are required' });
    }

    const platConfig = platforms[platform];
    if (!platConfig) {
      return res.status(400).json({
        error: `Unknown platform: ${platform}`,
        available: Object.keys(platforms),
      });
    }

    // Check required API keys
    const missing = platConfig.requires.filter((env) => !process.env[env]);
    if (missing.length > 0) {
      return res.status(503).json({
        error: `${platConfig.name} is not configured`,
        missing_keys: missing,
        tip: `Set ${missing.join(', ')} in .env`,
      });
    }

    // Instagram requires media
    if (platform === 'instagram' && !media_url) {
      return res.status(400).json({
        error: 'Instagram requires a media_url (image or video)',
        tip: 'Generate an image first via /api/image/generate, then post with the returned URL.',
      });
    }

    // ── Schedule support ──────────────────────────────
    if (schedule) {
      const scheduledTime = new Date(schedule);
      if (isNaN(scheduledTime.getTime())) {
        return res.status(400).json({ error: 'Invalid schedule date format. Use ISO 8601.' });
      }
      if (scheduledTime <= new Date()) {
        return res.status(400).json({ error: 'Schedule time must be in the future' });
      }

      // Push to Redis for scheduled execution
      try {
        const { getQueue } = await import('../queue.js');
        const queue = getQueue('social-scheduled');
        await queue.add('publish', {
          platform,
          content,
          media_url,
          hashtags,
          scheduled_for: schedule,
        }, {
          delay: scheduledTime.getTime() - Date.now(),
        });

        return res.json({
          success: true,
          scheduled: true,
          platform,
          scheduled_for: schedule,
          content_preview: content.substring(0, 100) + (content.length > 100 ? '...' : ''),
        });
      } catch (queueErr) {
        console.warn('[Social] Redis queue unavailable, falling back to response-only', queueErr.message);
        return res.json({
          success: true,
          scheduled: true,
          platform,
          scheduled_for: schedule,
          content_preview: content.substring(0, 100) + (content.length > 100 ? '...' : ''),
          warning: 'Redis queue unavailable — schedule is not persisted. Restart Redis to enable scheduling.',
        });
      }
    }

    // ── Twitter/X Post ──────────────────────────────────
    if (platform === 'twitter') {
      try {
        const tweetPayload = { text: content };

        if (media_url) {
          // Upload media first
          const mediaResp = await axios.post(
            'https://upload.twitter.com/1.1/media/upload.json',
            { media: media_url, media_category: 'tweet_image' },
            {
              headers: {
                Authorization: `Bearer ${process.env.TWITTER_ACCESS_TOKEN}`,
                'Content-Type': 'application/x-www-form-urlencoded',
              },
            }
          );
          tweetPayload.media = { media_ids: [mediaResp.data.media_id_string] };
        }

        const response = await axios.post(platConfig.api, tweetPayload, {
          headers: {
            Authorization: `Bearer ${process.env.TWITTER_ACCESS_TOKEN}`,
            'Content-Type': 'application/json',
          },
        });

        return res.json({
          success: true,
          platform: 'twitter',
          tweet_id: response.data?.data?.id,
          url: `https://x.com/i/status/${response.data?.data?.id}`,
        });
      } catch (apiErr) {
        console.error('[Twitter API]', apiErr.response?.data || apiErr.message);
        return res.status(502).json({
          error: 'Twitter API error',
          detail: apiErr.response?.data || apiErr.message,
        });
      }
    }

    // ── Facebook Post ───────────────────────────────────
    if (platform === 'facebook') {
      const pageId = process.env.FACEBOOK_PAGE_TOKEN?.split('|')[0];
      try {
        const response = await axios.post(
          `${platConfig.api}/${pageId}/feed`,
          {
            message: [content, hashtags].filter(Boolean).join('\n\n'),
            link: media_url,
          },
          {
            params: { access_token: process.env.FACEBOOK_PAGE_TOKEN },
          }
        );

        return res.json({
          success: true,
          platform: 'facebook',
          post_id: response.data?.id,
        });
      } catch (apiErr) {
        console.error('[Facebook API]', apiErr.response?.data || apiErr.message);
        return res.status(502).json({
          error: 'Facebook API error',
          detail: apiErr.response?.data || apiErr.message,
        });
      }
    }

    // ── Instagram Post (Graph API) ──────────────────────
    if (platform === 'instagram') {
      try {
        const igUserId = process.env.INSTAGRAM_BUSINESS_ACCOUNT_ID;
        if (!igUserId) {
          return res.status(400).json({
            error: 'INSTAGRAM_BUSINESS_ACCOUNT_ID not set',
            tip: 'Set your Instagram Business Account ID in .env. Find it via: GET /me/accounts?fields=instagram_business_account from Facebook Graph API.',
          });
        }

        const accessToken = process.env.INSTAGRAM_ACCESS_TOKEN;

        // Step 1: Create media container
        const isVideo = media_type === 'video' || media_type === 'reel';
        const containerEndpoint = `${platConfig.api}/${igUserId}/media`;
        const containerPayload = {
          [isVideo ? 'video_url' : 'image_url']: media_url,
          caption: [content, hashtags].filter(Boolean).join('\n\n'),
        };
        if (isVideo) {
          containerPayload.media_type = 'REELS';
        }

        const containerResp = await axios.post(containerEndpoint, containerPayload, {
          params: { access_token: accessToken },
        });

        const creationId = containerResp.data?.id;
        if (!creationId) {
          return res.status(502).json({
            error: 'Failed to create Instagram media container',
            detail: containerResp.data,
          });
        }

        // Step 2: Wait for media to be ready (Instagram processes async)
        let statusCheck;
        let pollAttempts = 0;
        const maxPolls = 30;

        do {
          await new Promise((r) => setTimeout(r, 3000));
          pollAttempts++;

          const statusResp = await axios.get(
            `${platConfig.api}/${creationId}`,
            {
              params: {
                access_token: accessToken,
                fields: 'status_code,status',
              },
            }
          );
          statusCheck = statusResp.data;

          if (pollAttempts >= maxPolls) {
            return res.status(504).json({
              error: 'Instagram media processing timed out',
              creation_id: creationId,
              tip: 'The media may still publish. Check your Instagram account.',
            });
          }
        } while (statusCheck.status_code !== 'FINISHED');

        // Step 3: Publish the media
        const publishResp = await axios.post(
          `${platConfig.api}/${igUserId}/media_publish`,
          { creation_id: creationId },
          { params: { access_token: accessToken } }
        );

        return res.json({
          success: true,
          platform: 'instagram',
          post_id: publishResp.data?.id,
          creation_id: creationId,
          url: `https://instagram.com/p/${publishResp.data?.id?.split('_')[0] || ''}`,
        });
      } catch (apiErr) {
        console.error('[Instagram API]', apiErr.response?.data || apiErr.message);
        return res.status(502).json({
          error: 'Instagram API error',
          detail: apiErr.response?.data || apiErr.message,
        });
      }
    }

    // ── LinkedIn Post ───────────────────────────────────
    if (platform === 'linkedin') {
      try {
        const response = await axios.post(
          `${platConfig.api}/ugcPosts`,
          {
            author: `urn:li:person:${process.env.LINKEDIN_PERSON_URN || 'me'}`,
            lifecycleState: 'PUBLISHED',
            specificContent: {
              'com.linkedin.ugc.ShareContent': {
                shareCommentary: { text: [content, hashtags].filter(Boolean).join('\n\n') },
                shareMediaCategory: media_url ? 'IMAGE' : 'NONE',
                media: media_url
                  ? [
                      {
                        status: 'READY',
                        media: media_url,
                        title: { text: content.substring(0, 200) },
                      },
                    ]
                  : undefined,
              },
            },
            visibility: {
              'com.linkedin.ugc.MemberNetworkVisibility': 'PUBLIC',
            },
          },
          {
            headers: {
              Authorization: `Bearer ${process.env.LINKEDIN_TOKEN}`,
              'Content-Type': 'application/json',
              'X-Restli-Protocol-Version': '2.0.0',
            },
          }
        );

        return res.json({
          success: true,
          platform: 'linkedin',
          post_id: response.data?.id,
          url: `https://linkedin.com/feed/update/${response.data?.id}`,
        });
      } catch (apiErr) {
        console.error('[LinkedIn API]', apiErr.response?.data || apiErr.message);
        return res.status(502).json({
          error: 'LinkedIn API error',
          detail: apiErr.response?.data || apiErr.message,
        });
      }
    }

    res.status(400).json({ error: `Unhandled platform: ${platform}` });
  } catch (err) {
    console.error('[Social Post]', err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── Generate Social Content (AI-powered) ──────────────────
router.post('/generate-content', async (req, res) => {
  try {
    const {
      topic,
      tone = 'professional',
      platform,
      include_hashtags = true,
      count = 3,
      language = 'en',
    } = req.body;

    if (!topic) {
      return res.status(400).json({ error: 'Topic is required' });
    }

    const charLimits = {
      twitter: 280,
      facebook: 63206,
      instagram: 2200,
      linkedin: 3000,
    };

    const hashtagCategories = {
      design: ['#DesignInspiration', '#CreativeProcess', '#DesignThinking', '#VisualDesign', '#BrandIdentity'],
      social: ['#SocialMediaMarketing', '#ContentCreator', '#DigitalMarketing', '#SocialStrategy', '#ContentMarketing'],
      tech: ['#TechDesign', '#UIUX', '#WebDesign', '#ProductDesign', '#DesignSystem'],
      branding: ['#BrandStrategy', '#LogoDesign', '#BrandIdentity', '#VisualIdentity', '#BrandGuidelines'],
      typography: ['#Typography', '#TypeDesign', '#FontPairing', '#LetteringDaily', '#TypeInspiration'],
      general: ['#ContentCreation', '#CreativeStudio', '#DigitalContent', '#MarketingTips', '#BuildInPublic'],
    };

    // Try AI generation if OpenAI key is available
    if (process.env.OPENAI_API_KEY) {
      try {
        const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });
        const platforms = platform ? [platform] : ['twitter', 'instagram', 'linkedin'];
        const variants = [];

        for (const plat of platforms) {
          const limit = charLimits[plat] || 500;

          const prompt = plat === 'twitter'
            ? `Write ${count} ${tone} tweets about "${topic}". Each under ${limit} characters. Include a strong hook. Return as JSON array of strings.`
            : `Write ${count} ${tone} ${plat} posts about "${topic}". Each under ${limit} characters. Include headline, body, and call-to-action. Include relevant emojis. Return as JSON array of strings.`;

          const completion = await openai.chat.completions.create({
            model: 'gpt-4o-mini',
            messages: [
              { role: 'system', content: 'You are a social media content strategist. Return valid JSON only.' },
              { role: 'user', content: prompt },
            ],
            temperature: 0.8,
            max_tokens: 1000,
          });

          try {
            const content = JSON.parse(completion.choices[0].message.content);
            variants.push({
              platform: plat,
              character_limit: limit,
              variants: Array.isArray(content) ? content : [content],
            });
          } catch {
            // Fallback: treat as text
            variants.push({
              platform: plat,
              character_limit: limit,
              variants: [completion.choices[0].message.content],
            });
          }
        }

        return res.json({
          success: true,
          topic,
          tone,
          generated_by: 'openai',
          language,
          suggested_hashtags: include_hashtags ? hashtagCategories : null,
          content: variants,
        });
      } catch (aiErr) {
        console.warn('[AI Content] OpenAI error, falling back to templates:', aiErr.message);
      }
    }

    // Fallback: template-based generation
    res.json({
      success: true,
      topic,
      tone,
      platform: platform || 'all',
      character_limit: platform ? charLimits[platform] : charLimits,
      suggested_hashtags: include_hashtags ? hashtagCategories : null,
      generated_by: 'template',
      tip: 'Set OPENAI_API_KEY in .env for AI-generated content.',
      prompt_templates: platform
        ? {
            [platform]: platform === 'twitter'
              ? `Write a ${tone} tweet about "${topic}". Keep it under ${charLimits[platform]} characters. Include a hook.`
              : `Write a ${tone} ${platform} post about "${topic}". Include a headline, body, and call-to-action.`,
          }
        : Object.fromEntries(
            Object.entries(charLimits).map(([p, limit]) => [
              p,
              p === 'twitter'
                ? `Write a ${tone} tweet about "${topic}". Under ${limit} chars.`
                : `Write a ${tone} ${p} post about "${topic}". Under ${limit} chars.`,
            ])
          ),
    });
  } catch (err) {
    console.error('[Social Content]', err.message);
    res.status(500).json({ error: err.message });
  }
});

export { router as socialRouter };
