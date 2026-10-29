# Selected captions and reusable editorial recipes

## Approval summary

Rich Ideas can contain a caption, production instructions and source checks. The current Create post action transfers the complete body. Extend that existing action so a non-empty text selection can be transferred explicitly, while retaining the whole-body handoff when no selection exists. A clear selection-specific button label and short explanatory text tell the user which text will reach the post composer. The source Idea, owned media and labels retain their current lifecycle.

Provide three copyable generation recipes in one guide: student opportunities/events, people/research, and website news for manual CMS transfer. These recipes use the existing Idea writing assistant and shared DF style, with supplied facts and reviewable output. They are reference recipes rather than new caption-library records or a new preset system. After saving and reopening the full Idea, select only the intended caption for transfer. The source facts must be pasted into the Generate prompt because generation does not send the existing Idea body. Their output separates the caption from production notes and source checks; website news remains an article draft.

The user accepted this first improvement batch in the current chat with “agreed”. Approval mode: executable batch. This authorizes focused implementation, local synthetic verification, review, normal task-branch commits/pushes and a draft PR. Terminal: reviewed source and draft PR with current CI and real browser evidence, or an exact capability blocker after independent work is finished. Merge, ready, release, deployment, live writes, social publication, paid provider calls, deletion, credentials and client configuration changes are outside this batch.

There is no schema, request/API, dependency, infrastructure or provider change. A small prompt-contract correction makes generation without a selected channel honor explicitly requested production-brief/article formats; ordinary caption requests remain caption-only, channel-bound generations keep their existing output and limits, and rewrite modes keep their current behavior. The meaningful risk is stale/accidental text selection or a misleading action label. Selection survives focus transfer to the action button by pointer or keyboard. Clear it on body changes (including assistant insert/replace), collapsed selections and whitespace-only selections; the action label and transferred payload must agree. Persisted source preservation requires saving and reopening the full Idea before handoff; the handoff does not autosave unsaved edits. Recovery is an ordinary source correction/revert before any separately authorized release.

## Execution details

### Primitive impact and source baseline

- Idea: reuse its title, full body, media, labels and owner. No new field or persistence contract.
- Post draft composition: extend the existing browser handoff with an explicit selected-text input. The server remains responsible for ordinary post/media validation and ownership.
- Writing guidance: compose the shared DF guide with three reusable task recipes. Examples are synthetic; reference writing never supplies new factual claims.
- Existing title fallback and full-body handoff remain available when no non-empty selection exists. No parser infers a caption from headings.

Source and target: origin/release/2.0.0-uzh at 1ce1a8282cc00b99e154687abb8c52cb359b34cc. Worktree: /private/tmp/trypost-v2-public-source/trees/rs/trypost-editorial-handoff. Branch: rs/trypost-editorial-handoff. The main checkout is a worktree-control root; all deliverables stay in this task worktree. Project artifact root: docs/project/. Main session owns implementation, verification, integration and delivery. One independent Astra plan critique is required before implementation; one integrated independent review covers the final range.

### Sequence and intended files

1. Implement selected-text handoff in resources/js/components/create/ideas/IdeaEditorDialog.vue. Capture the textarea's explicit selection; use its exact text rather than guessing structure. Preserve it across pointer/keyboard blur to the action; clear it when the body changes, including native assistant insert/replace, or when a selection collapses/becomes whitespace. Keep existing media/labels/composer navigation. Add selection label/help translations to lang/*/create.php, following the existing locale set and avoiding unrelated formatting.
2. In resources/views/prompts/post_content/assistant.blade.php, resolve the existing conflict between requested output format and the final caption-only directive for generation/regeneration without a selected channel. Default to a caption; allow a production brief or article only when explicitly requested. Leave channel-bound and rewrite prompt branches unchanged. Inspect renders for unbound generate/regenerate, bound generation and rewrite; reuse existing AI routing/request tests with synthetic provider responses. No prose-pinning tests and no claim that fake output proves provider writing quality.
3. Extend tests/Browser/CreateIdeaEditorTest.php for selected caption versus internal notes, exact persisted source-body equality after handoff, unchanged media/labels, selection survival across pointer/keyboard focus transfer and clearing after editing/collapse/whitespace and assistant changes, and default full-body/title behavior. Reuse its existing helpers and synthetic factories. No new test helper module or application test endpoint.
4. Add docs/project/df-editorial-recipes.md with the three copyable recipes and brief usage instructions. Paste the complete factual brief and recipe into the Generate prompt; never imply the pre-existing Idea body is sent. Save and reopen the full result before selecting a caption. The first two allow explicitly requested carousel/Reel production briefs with separate caption, visual/spoken instructions and source checks. New text follows the existing user-language owner and DF style. Exact quotes remain exact; proposed paraphrases are identified. One primary CTA uses only supplied facts. Website output has title, lead, body, image caption and sources for manual transfer. Keep the source plan in docs/project/2026-10-29-editorial-handoff-plan.md.
5. Save and reopen the complete synthetic source Idea, then verify a synthetic carousel brief and a synthetic Reel brief through the local Idea-to-composer flow. This proves the handoff and editorial structure, not provider writing quality. No paid calls. Inspect English desktop and German compact captures, especially the selection label and footer.
6. Review the committed complete range, address verified in-scope findings, create one draft PR targeting release/2.0.0-uzh, attach reviewed synthetic screenshots through the authenticated forge CLI and complete required CI. No human review requests. Stop exact local test services after final verification; preserve worktree and data.

### Test portfolio

| Consequential behavior | Obligation | Primary seam | Existing protection and realistic failure |
| --- | --- | --- | --- |
| Selected caption alone reaches composer; full source remains | Extend existing browser test | Real Idea textarea, button, composer and persisted source | Existing whole-Idea handoff test cannot detect internal production notes leaking into selected-caption flow |
| Stale/empty selection never silently replaces intended text | Extend same browser case | Body edit, assistant insertion/replacement, collapsed/whitespace selection, keyboard/pointer blur and handoff | Existing selection-free flow has no captured state to invalidate |
| Requested unbound production formats coexist with existing captions | Reuse existing request/routing tests; manual render review | Existing prompt template mode/platform branches | The final caption-only directive currently contradicts requested briefs/articles; fake outputs prove transport only |
| Media/labels and default full-body/title handoff remain | Extend/reuse existing browser case | Native draft payload and source reload | Existing handoff and media ownership coverage remains authoritative; selected content must not alter unrelated payloads |
| Copyable recipes remain human guidance rather than factual authority | No content-pinning test | Manual source and synthetic brief review | No tests pin recipe wording, translations, prose hashes or sample contents |

### Verification and limits

Use the repository's installed npm/Composer tooling and lockfiles, inside a local container. Reuse the existing local PHP/Node tools image only after checking versions and lock compatibility; reconcile Composer dependencies to the current lock. Use an isolated synthetic PostgreSQL database and native Pest browser tests. Run touched-file Prettier/ESLint, Vue TypeScript, asset build, Pint on touched PHP, relevant localization and focused existing browser regressions. Run the applicable focused scan, inspect exact staged content and retain current evidence. Test fixtures never use real accounts, posts, quotes or environment identifiers. Browser capture metadata records source revision, locale, viewport and actual interaction checks. Keep screenshots and review receipts in ignored docs/project/_local/.

## Progress

- Root outcome: selected captions can become post drafts without copying production notes; three copyable recipes support editorial preparation.
- Approved batch and terminal: user-agreed source implementation through reviewed draft PR/CI, without release/live effects.
- First useful acceptance: achieved. Synthetic carousel and Reel captions open the composer with the expected image and label while the exact saved source body, title and media owner remain intact.
- State: source complete, final independent review and hosted CI pending. Main remains the sole writer. One revised Astra plan critique approved the bounded prompt correction and selection/source-preservation contract.
- Verification: 150 focused AI/localization tests passed with one existing skip. Five focused browser cases passed; the two capture cases were then strengthened to require actual image loading and passed again. Pointer desktop English and keyboard compact German captures were inspected. Touched ESLint, Vue TypeScript, Pint and asset build passed. Prompt render inspection covers unbound generate/regenerate, bound generation and rewrite. No paid provider call or model-writing-quality proof is claimed.
- Focused scanner: all three code targets parsed. A warning points to existing test-owned temporary-file cleanup, outside the changed behavior; source inspection rejects it as a new vulnerability. The final test-fixture adjustment is subject to the same focused scan and review.
- Formatting: the installed formatter was run; unrelated pre-existing reflows were restored to keep the semantic diff focused. The repository's linter workflow formats in place.
- Working context: isolated native Docker test services, source bind-mount and read-only Git metadata. No DevPod/devrouter runtime was used. Stop the exact task services after the last runtime-dependent check and preserve the worktree.
- Current blocker: none. Final review, screenshot publication and hosted checks remain before the source-delivery terminal.
- Package tier: full path; one coherent enhancement. Substantive size is recorded in the ignored delivery receipt against the source target, excluding project artifacts.
