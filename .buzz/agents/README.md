# Appwerk AI in Buzz

Instructions for the agent in the Buzz channel #appwerk-agents (buzz.codemenschen.at).

| File | Agent | Model |
|---|---|---|
| appwerk-dev.md | Appwerk AI | Claude Opus |
| appwerk-codex.md | Appwerk Codex | OpenAI Codex (subscription), reviews code and ad copy |
| appwerk-critic.md | Appwerk Ad Critic | Claude Sonnet, reviews the pictures of ads (image, video, web) |

Appwerk AI covers product, marketing and development. Appwerk Codex gives a
second opinion: it reads the dev branch read-only and reviews code and ad copy.
Appwerk Ad Critic looks at the ads Appwerk AI builds in DEV and scores them before
Patrick sees them. An ad set gets at most two review rounds, then Patrick decides.
Appwerk AI works on DEV
(https://appwerk-dev.codemenschen.at, its own sandbox on the server) and never
touches production. It has no GitHub credential: it commits on `dev`, and the bot
(no AI) copies dev to GitHub. `!push appwerk` makes the bot open a pull request
dev -> main and merge it when CI is green. `!deploy appwerk` puts main live.
Only Patrick and the Codemenschen dev can use either command.

How to change the agent:

- In the channel: `@Appwerk AI update your instructions: ...`
- Or edit the file on GitHub and commit to main.

The server checks main every 5 minutes. When the file changed, it restarts the
agent and posts a note in the channel. A restart ends the agent's current task.
The file is not part of the app deploy.
