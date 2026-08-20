# Company-Continuity Provision — Draft Design

> **Status:** DRAFT for counsel red-line. Answers the licensee question: *"What happens to my license if codemenschen.at itself shuts down?"* The FAQ currently promises a contractual answer; this is that answer's design.

## Why this exists
The Licensee's entire position depends on the Company continuing to exist: the Company holds the IP, the infrastructure, and the exclusive right to develop. Without a continuity provision, Company insolvency leaves licensees holding a license to software nobody may maintain — the single worst-case scenario in the model, and the first question a diligent buyer asks. A credible answer is both a legal necessity and the platform's strongest trust asset.

## Recommended structure: escrow + license conversion (two layers)

### Layer 1 — Source-code escrow
- Complete source code, build instructions, infrastructure-as-code, and credentials inventory for every licensed app deposited with an independent escrow agent (e.g. a Software-Escrow provider or Austrian notary arrangement).
- Deposits refreshed on a fixed cadence (e.g. quarterly, or per major release) with a deposit log visible in the Licensee dashboard — the refresh cadence is itself published, so licensees can verify the escrow isn't stale.
- **Release triggers** (enumerated, objective): opening of insolvency proceedings over the Company; cessation of business operations for more than [60] days; failure to provide contracted maintenance for more than [90] days after formal notice.
- On release: the affected Licensee receives the deposit for their app.

### Layer 2 — Automatic license conversion on trigger
- Upon a release trigger, the Licensee's exclusive operating license **automatically converts into a perpetual, fully-paid, exclusive license including the right to modify, maintain, and commission third-party development** of their app's code.
- Effect: the lock-in (exclusive development by the Company) dies with the Company. The Licensee can take the escrowed code to any developer.
- The retainer obligation ends at the trigger date.

## Points for counsel
1. **Insolvency-proofing:** structure the conversion + escrow so it survives an Austrian insolvency administrator's choice rights (§ 21 IO — Insolvenzverwalter's right to reject pending contracts). This is the hard legal question; conditional perpetual licenses granted *now* (with use rights springing on trigger) are the usual technique — confirm the Austrian-law-robust construction.
2. Escrow agent selection and cost (borne by the Company; priced into the retainer — do not itemize as a licensee surcharge).
3. Trigger verification: who certifies a trigger occurred (escrow agent on documentary evidence vs. court)? Avoid constructions where the insolvent Company must cooperate.
4. Interaction with the resale market: can a suspended-but-relisted app's escrow rights transfer to the new licensee? (They should.)
5. Third-party dependencies (app-store accounts, API keys, payment processors): escrow can't cover accounts owned by the Company — define the handover obligation and a power-of-attorney or account-transfer mechanism per store's terms.

## Website surface (after counsel sign-off)
- FAQ answer upgrades from "under legal review" to a plain-language description of escrow + conversion.
- One line in the homepage risk section: *"If we disappear, the code doesn't: every licensed app's source is in independent escrow, and your license converts to full self-management rights."* — only once it is contractually true.
