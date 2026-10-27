# Writing styles in Ideas generation

## Approval summary

Ideas generation already supports model selection, while the writing assistant also supports an optional configured writing style. Extend Ideas with that same Default/DF choice so a user can prepare consistent content briefs before turning them into social posts or website news drafts. Reuse the existing style catalog, translated labels and selection control. Resolve the chosen style before calling the model and include its literal guidance in the original structured generation request.

Default preserves the current Ideas instructions. DF uses the existing bounded guide loader. The generated object remains a title and an idea brief; this does not create a finished-caption or website-publishing workflow. Model selection, authorization, language, validation and failure behavior retain their existing owners. There are no schema, provider, credential or deployment changes.

The user approved implementation and delivery as a reviewed draft PR with CI and browser verification. Approval mode: executable batch, ending at that source-delivery boundary. It covers local synthetic verification, ordinary task-branch commits/pushes and the draft PR. Merge, release, deployment, live data changes, paid generation and social publishing remain outside this batch. Local provider responses are synthetic, so verification proves request wiring and interaction rather than writing quality.

## Contract and ownership

| Product primitive | Disposition | Contract |
| --- | --- | --- |
| Writing style selection | Reuse | Null/default needs no guide; DF resolves through WritingStyleCatalog; invalid or unavailable choices fail before generation. |
| Model selection | Reuse | The selected structured-capable model and style travel together without overriding each other. |
| Generated idea | Extend composition | Existing title/body schema, user language and brief semantics take priority over style guidance. Generation alone stores no idea. |

Main owns backend, tests, integration, verification and delivery. One bounded frontend worker owns the shared selector extraction and its two callers. Reviewers are read-only. Execution mode: standard. Full path: the package extends a request contract and shares a control across two workflows. A native independent planner approved this frozen scope before delivery. No product decision remains open.

## Test portfolio

| Risk | Obligation | Primary proof |
| --- | --- | --- |
| Wrong style or model reaches the provider | Extend existing | AiModelRoutingTest exercises all configured model routes with Default and DF using synthetic HTTP responses and a test-owned guide. |
| Invalid style causes a model call or Default requires a guide | Extend existing | GenerateIdeaTest checks omitted/null/default and unknown/unavailable/malformed selections before any model invocation. Existing catalog validation tests remain authoritative. |
| Shared control breaks selection, reset or idea handoff | Extend existing | CreateIdeaEditorTest exercises model/style combinations, switching back to Default, reopening, and saving the selected generated idea. Real local browser captures cover desktop English and compact German. |

## One implementation slice

Deliver one coherent enhancement on `rs/trypost-idea-writing-styles`, targeting `release/2.0.0-uzh`. Extend GenerateIdeaRequest, IdeaGenerateController, GenerateIdea and IdeaGenerator; pass the resolved guide into the existing Ideas Blade prompt. Extract the existing inline style selector into `resources/js/components/ai/AiWritingStyleSelect.vue` and use it from WritingAssistantPanel and GenerateIdeasDialog. Extend the three existing test files above. The selector is the only new runtime file.

Run Pint, focused backend tests, touched frontend formatting/lint, TypeScript and the asset build. Use the isolated local application and synthetic account for browser interaction/captures; keep fixtures and provider fakes outside production code. Run the focused source scan. Inspect the exact diff, commit the complete slice, then run simplification and risk review. Apply verified in-scope corrections and repeat affected verification only. Complete integrated Opus review against the held final range while PR CI runs. Create/update one draft PR with inspected native screenshot attachments and accurate limitations. Stop exact local containers after the final runtime check.

Failure recovery is a source correction within this branch. A missing capability blocks only its dependent check. New dependencies, schema, authority, cost or data movement require a new ruling. Preserve the worktree and branch after delivery.

## Progress

The implementation is present. Focused backend verification passed: 108 tests. Asset build, touched frontend lint and full TypeScript check passed. Browser verification, focused scan, committed-range reviews and hosted CI remain in progress. Added tests extend existing seams; no new test file or production test hook was added. The source batch remains unmerged and undeployed.
