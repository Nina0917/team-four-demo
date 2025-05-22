import fetch from 'node-fetch';

const slackWebhook = process.env.SLACK_WEBHOOK_URL;
const githubToken = process.env.GITHUB_TOKEN;
const owner = process.env.REPO_OWNER;
const repo = process.env.REPO_NAME;
// const staleDays = parseInt(process.env.STALE_DAYS || '2');
// const cutoff = new Date(Date.now() - staleDays * 24 * 60 * 60 * 1000);

// stale for 2 minutes
const staleTime = 2 * 60 * 1000;
const cutoff = new Date(Date.now() - staleTime);

const headers = {
  Authorization: `token ${githubToken}`,
  'User-Agent': 'stale-pr-bot',
  Accept: 'application/vnd.github.v3+json',
};

const run = async () => {
  const res = await fetch(`https://api.github.com/repos/${owner}/${repo}/pulls?state=open&per_page=100`, {
    headers,
  });

  const prs = await res.json();
  const stale = prs.filter(pr => new Date(pr.updated_at) < cutoff);

  if (!stale.length) return;

  let message = `*Stale PRs (≥ ${staleDays} days old)* 📉\n`;

  stale.forEach(pr => {
    message += `• <${pr.html_url}|#${pr.number} - ${pr.title}> by \`${pr.user.login}\` (last updated ${pr.updated_at})\n`;
  });

  await fetch(slackWebhook, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ text: message }),
  });
};

run().catch(err => {
  console.error('Failed to fetch PRs or send message:', err);
  process.exit(1);
});
