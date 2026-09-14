# Appwerk agents in Buzz

Instructions for the agents in the Buzz channel #appwerk-agents (buzz.codemenschen.at).

| File | Agent | Model |
|---|---|---|
| appwerk-product.md | Appwerk Product | Sonnet |
| appwerk-marketing.md | Appwerk Marketing | Sonnet |
| appwerk-dev.md | Appwerk Dev | Opus |

How to change an agent:

- In the channel: `@Appwerk Dev update the Marketing instructions: ...`
- Or edit the file on GitHub and commit to main.

The server checks main every 5 minutes. When a file changed, it restarts that agent and posts a note in the channel. A restart ends the agent's current task. The files are not part of the app deploy.
