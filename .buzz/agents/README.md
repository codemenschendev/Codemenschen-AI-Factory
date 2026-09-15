# Appwerk AI in Buzz

Instructions for the agent in the Buzz channel #appwerk-agents (buzz.codemenschen.at).

| File | Agent | Model |
|---|---|---|
| appwerk-dev.md | Appwerk AI | Opus |

One agent covers product, marketing and development. It works on DEV
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
