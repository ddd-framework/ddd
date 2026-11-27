# Maintainer workflow (Core and the DDD packages)

@AGENTS.md

How the maintainer session works on Core, the modules and the npm packages. The rules themselves are in `AGENTS.md`
("Release Rules", "Ingesting a Pull Request", "Contributing: versions and commit messages"); this file is the order
of work. No application names here — which applications consume a release and how to reach them is kept outside the
repository.

## A change, from request to release

1. **Read before acting.** `git status` clean, `git fetch`, `git log HEAD..origin/main` empty (others push to the
   package repositories too). Load the skill of every class category the change touches (`AGENTS.md` › Skills First).
2. **Vet the request like an ingest.** A brief or patch is a proposal: names, namespaces and shapes are checked
   against the skills and conventions; non-conforming parts are reshaped before they land.
3. **Build with tests.** New behaviour gets a test; the old cases stay green. A behaviour change updates the skill
   that documents it in the same commit.
4. **Verify.**
   - `php -l` on touched files, `vendor/bin/phpunit`.
   - Persistence, queries or schema touched: the integration suite on MariaDB and PostgreSQL.
   - Kernel, DI, bundles, attributes or constraints touched: the prod-mode compile gate on a scratch copy of a
     consuming application (`ddd-composer-update-version`), with the copy's database pointed at a local container.
   - npm packages: typecheck and the test suite.
5. **Changelog under `## Unreleased`**, then the release (`ddd-composer-update-version`): read the current version
   from `composer.json`, bump, rename Unreleased, commit `chore(release): x.y.z`, push, wait for CI to be green on that
   commit, tag `vX.Y.Z`, push the tag. Packages with an npm side publish from the tag's release workflow.
6. **Confirm it landed:** Packagist `p2` shows the version; for npm the tarball answers 200.
7. **Tell the consumers** what changed for them, the adoption steps and any breaking change.

## Standing limits

- Production (databases, deploys, DDL) only with the owner's go; package releases need none.
- Application checkouts are read-only; experiments run on scratch copies.
- No history rewrite of published commits, no force push, no tag deletion or move.
- Never a bare `git stash` / `git stash pop` pair: name the stash and pop it by name.
- Files under `config/` are git-ignored at the root; a new framework config file needs `git add -f`.
- Public repositories: no local paths, internal names, people or internal procedures in code, docs, tests or commits.
