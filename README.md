# Content Hub

A shareable, multi-user platform: each person signs up, adds their businesses, connects their social accounts with **Connect** buttons, and turns on **Autopilot**, which writes and posts on a schedule. PHP 8.0+ (with the GD extension for image cards, standard on cPanel) and SQLite on any cPanel host. No paid services.

## What's in it
- **Pipeline tab**: an n8n-style map of Source → Business → Accounts, with live status. Tap a node to configure it.
- **Quick start**: type a website link. It reads the name, logo, description, blog feed and social links, then creates the business.
- **Connect buttons**: Facebook and Instagram (Meta), LinkedIn, X. Tokens are stored encrypted. Telegram, Discord and manual keys are also supported.
- **Any AI**: Gemini, Claude, OpenAI, Groq, OpenRouter, Mistral, DeepSeek, or any OpenAI-compatible URL. Each user picks a provider, model and key in Settings, and "Test AI" checks it. An optional **Backup AI** takes over automatically if the main one errors or hits its free limit. Free tiers: Gemini, Groq, Mistral, OpenRouter ":free" models.
- **Autopilot** (per business): choose days, time, timezone, tone, topics and mode.
  - *Review mode* saves each post as a draft for you to **Approve**.
  - *Auto mode* publishes by itself.
  - Optional free AI image (needed for Instagram).
- **Blog feeds (RSS)**: new blog posts become drafts (or auto-publish) on every connected network.
- **Multi-user**: every query is scoped to the signed-in user, with CSRF protection, login throttling, per-user business and AI quotas, and "delete my account and all data".
- **Timeline tab** (topic → daily posts):
  - Type a topic and press **Start timeline**. The AI plans N days (concepts, worked examples, MCQs, common mistakes, exam tips, a recap, and an advert every Nth day) and writes one post per day.
  - Each post gets a branded 1080×1350 image card (drawn on the server with GD and the bundled Poppins font, OFL licence), plus separate Instagram/Facebook, LinkedIn and WhatsApp text.
  - **Up next** queue: when a timeline ends, the next topic starts the following day. Each line can be `Topic | days | level`. Lines starting with `#` are skipped. A ready-made syllabus queue is in `queues/amir-gill-accounting.md`.
  - Timelines link to the owner's matching YouTube videos.
- **Discord approval**: each draft is posted to a Discord channel N hours before its slot.
  - Reply `ok` to approve, `no` to skip, `edit: new caption` to use your own text, or write any instruction ("make it shorter", "the answer is B") and the AI rewrites it. You can also tap ✅ or ❌.
  - After approval the bot sends the WhatsApp version to copy and forward.
  - Commands: `topic: Depreciation | 10 | A Level`, `queue: Control accounts`, `status`, `help`.
  - Optional: "post anyway if nobody replies".
- **YouTube channel**: paste a channel link (in Quick start or on the Timeline tab). New uploads, Shorts included, become branded posts using the video thumbnail and are sent for approval. No API key needed; it reads the channel's public feed.
- **LinkedIn image posts**: posts with an image upload it to LinkedIn instead of going out as text only.

## Install
1. Upload the `hub/` folder, e.g. to `public_html/hub/`.
2. Copy `config.sample.php` to `config.php`. Set at least:
   - `data_dir`: a folder **outside** `public_html` (e.g. `/home/USER/hub_data`). It holds the database and the encryption key. Back both up together.
   - `cron_key`, plus `signup` (`open`, `invite` or `closed`) and `invite_code`.
   - `gemini_key`: free key from https://aistudio.google.com/apikey. Users can add their own in Settings.
3. Use HTTPS. Sign-in cookies and OAuth require it.
4. Open the site and **create the first account**. It is always allowed and is yours.
5. **Scheduler** (cPanel → Cron Jobs, every minute): `* * * * * /usr/local/bin/php /home/USER/public_html/hub/cron.php`. Or use free cron-job.org to hit `cron.php?key=YOUR_CRON_KEY`. The cron job drives Autopilot, blog import, scheduled posts and retries.

## Enabling the Connect buttons (one-time, by you the owner)
Register this redirect URI in **every** app: `https://yourdomain.com/hub/oauth.php`

| Network | Where | Needed in config.php | Notes |
|---|---|---|---|
| Facebook + Instagram | developers.facebook.com → Create app (Business) | `meta_app_id`, `meta_app_secret` | Permissions: `pages_show_list`, `pages_manage_posts`, `pages_read_engagement`, `instagram_basic`, `instagram_content_publish`. The Instagram account must be Business/Creator and linked to a Page. |
| LinkedIn | linkedin.com/developers → add the "Share on LinkedIn" product | `linkedin_client_id`, `linkedin_client_secret` | Personal profile posting is self-serve. Company pages need approval for the Community Management API (then set `linkedin_org` to true). Tokens last about 60 days, then the account shows "expired — reconnect". |
| X | developer.x.com → app with OAuth 2.0 (Web App) | `x_client_id`, `x_client_secret` | Text only. Tokens refresh automatically. |
| Telegram | @BotFather | (none, per user) | Users paste a bot token and chat ID. |

### Important: sharing the link with other people
- **Meta**: while your app is in *Development mode*, only people you add as testers or developers can connect Facebook and Instagram. For anyone else you need **Meta App Review** (permissions above) and usually **Business Verification**, plus a privacy-policy URL. This takes days or weeks and is Meta's rule, not something the code can bypass.
- **X free tier**: about 500 posts per month for the **whole app**, shared by every user. A shared hub will hit this fast.
- **Gemini free tier** is rate-limited. `max_ai_per_user_day` caps use, and users can add their own key.
- You are storing other people's social tokens. They are encrypted, but keep `secret.key` and the database safe and off the web root, and publish a privacy policy.

## Discord approval bot (free, about 5 minutes)
1. discord.com/developers/applications → New Application → Bot → Reset Token. Copy the token.
2. On the Bot page, turn on **Message Content Intent**.
3. Paste the token on the Timeline tab. An "Add this bot to your server" link appears (permissions: view and read history, send, embed, attach, react).
4. Discord → User Settings → Advanced → Developer Mode. Right-click the approvals channel → Copy Channel ID, and paste it.
5. Save, then press **Send test to Discord**.

The bot needs no always-on server. The every-minute cron job reads the channel, so approvals take effect within about a minute. Use the cPanel cron (not a 30-second web cron), because writing a post with AI can take 20–40 seconds.

## WhatsApp
WhatsApp has no free API for posting to a Channel or Status. The WhatsApp Business Cloud API only sends paid template messages to people who opted in. So each approved post comes with a ready-made WhatsApp version in Discord to copy and forward, and the WhatsApp number on every card brings enquiries in directly.

## Limits
- X posts are text and link only. LinkedIn posts carry text, a link or one image. There is no video or Reels posting yet; upload Reels by hand.
- AI images use the free, keyless pollinations.ai service. If it is down, the post goes out text-only, and Instagram is skipped for that post.

## Tests
Run against mocked APIs, so no real accounts were touched: 40 core checks (OAuth, publishing, retries, token refresh, autopilot, discovery, RSS, SSRF) and 35 web checks (signup, CSRF, user isolation, secrets, uploads, throttling). Real Meta, LinkedIn and X logins have not been tested, because they need your developer apps.
