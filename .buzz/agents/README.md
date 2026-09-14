# Appwerk AI in Buzz

Instructions for the agent in the Buzz channel #appwerk-agents (buzz.codemenschen.at).

| File | Agent | Model |
|---|---|---|
| appwerk-dev.md | Appwerk AI | Opus |

One agent covers product, marketing and development. It works on DEV
(https://appwerk-dev.codemenschen.at, its own sandbox on the server) and never
touches production. Production changes only with `!deploy appwerk`, run by the
Deploy Bot without any AI.

How to change the agent:

- In the channel: `@Appwerk AI update your instructions: ...`
- Or edit the file on GitHub and commit to main.

The server checks main every 5 minutes. When the file changed, it restarts the
agent and posts a note in the channel. A restart ends the agent's current task.
The file is not part of the app deploy.
