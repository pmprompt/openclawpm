---
name: review-and-release
description: Unified workflow for comprehensive code review, cleanup, testing, documentation updates, and automated release. Performs branch validation, code quality checks, auto-fixes issues, runs Pest tests, updates docs, and handles PR approval/merge/tagging. Use when preparing changes for release or when users request code review, PR creation, or release management.
---

# Review and Release

Unified workflow for comprehensive code review, testing, documentation, and release automation.

## Overview

This skill performs a complete review-and-release cycle:
1. **Branch validation** - Ensure clean working state
2. **Code review** - Quality checks, security review, auto-fixes
3. **Testing** - Run Pest tests with smart caching
4. **Documentation** - Update AGENTS.md, README.md, CHANGELOG.md
5. **Git workflow** - Single clean commit, push, create/update PR
6. **Release** - Approve, squash merge, tag (unless `--review-only`)

## Execution Flow

Phases execute in order:

1. **Phase 1 (Branch)** - Always runs
2. **Phase 2 (Review)** - Always runs, auto-fixes safe issues  
3. **Phase 3 (Tests)** - Auto-skips if recent passing cache detected
4. **Phase 4 (Docs)** - Smart updates based on what changed
5. **Phase 5 (Git)** - Always runs, creates/updates PR
6. **Phase 6 (Release)** - Runs unless `--review-only` flag set

## Flags

- `--review-only` - Stop after Phase 5 (create PR but don't merge/release)
- `--skip-tests` - Skip Phase 3 entirely
- `--dry-run` - Preview changes without executing
- `--separate-commits` - Create separate commits for code and docs (default: single commit)

## Phase 1: Branch Validation

### 1.1 Detect State
```bash
git branch --show-current
git status --porcelain
git fetch origin
```

### 1.2 Handle Edge Cases

**On main/master with uncommitted changes:**
- Analyze modified files to suggest branch name
- Create branch: `git checkout -b feature/{suggested-name}`
- Continue

**On main/master without changes:**
- Abort: "Nothing to review. Make changes first or checkout a feature branch."

**On feature branch:**
- Check if behind origin/main
- Merge or rebase if needed
- Handle conflicts

### 1.3 Determine Scope
```bash
git diff --name-only origin/main...HEAD
```

Categorize: PHP files, bash scripts, config, tests, docs

## Phase 2: Code Review

### 2.1 Read Modified Files
Use Read tool on all modified files.

### 2.2 PHP CLI Quality Checks

**Command Structure (Laravel Zero):**
- Check commands extend base Command class properly
- Verify handle() method exists and returns int
- Check for proper type hints and return types

**Code Style:**
```bash
cd cli && vendor/bin/pint --dirty
```

**Security Checks:**
- Hardcoded secrets/API keys
- Debug statements left in code
- Unsafe shell exec calls

### 2.3 Bash Script Quality Checks

**Idempotency Verification:**
- Check scripts can run multiple times safely
- Look for proper existence checks before creation

**Error Handling:**
- Verify `set -euo pipefail` or equivalent
- Check for proper exit codes

**Security:**
- No hardcoded credentials
- Proper variable quoting
- Safe temp file handling

**AGENTS.md Compliance:**
- Scripts print next-step commands
- Scripts print health checks
- Idempotent as per non-negotiables

### 2.4 Auto-Fix Safe Issues

**Always fix:**
- Remove `dd()`, `dump()`, `var_dump()`, `console.log()`
- Remove unused imports
- Run Pint for code style

**Never auto-fix:**
- Logic changes
- Security-sensitive code
- Breaking changes

### 2.5 Generate Review Summary

```
## Code Review
- [X] PHP code style (Pint)
- [!] Missing type hints in Command::handle()
- [X] Bash scripts idempotent
- [!] Debug statement in provision_sprite.sh

## Auto-Fixes Applied
- Removed 2 debug statements
- Applied Pint formatting to 3 files
- Removed 4 unused imports
```

## Phase 3: Testing

### 3.1 Check Test Cache
```bash
ls -la cli/.phpunit.result.cache 2>/dev/null
```

**Auto-skip if:**
- Cache exists and < 30 min old
- No test files modified
- AND no `--skip-tests` flag

### 3.2 Run Tests
```bash
cd cli && vendor/bin/pest
```

### 3.3 Fix Failures
- Analyze test output
- Fix source code or tests
- Re-run until passing

## Phase 4: Documentation Updates

### 4.1 Determine Changes

**Agent-facing (update AGENTS.md):**
- New CLI commands
- New scripts or workflows
- Architecture changes
- New environment variables

**User-facing (update README.md):**
- New features
- Changed CLI usage
- New commands
- Installation changes

**Always update CHANGELOG.md**

### 4.2 Apply Updates

**AGENTS.md:**
- Add new commands under appropriate sections
- Update workflow docs
- Add env vars to `.env.example`

**CHANGELOG.md:**
- Determine next version from git tags
- Add entry with date and narrative description
- Link to PR

**README.md:**
- Update if user-facing changes exist

## Phase 5: Git Workflow

### 5.1 Stage and Commit

**Default (single commit for clean squashing):**
```bash
git add -A
git commit -m "feat: {description}

- Code review and auto-fixes applied
- Documentation updated as needed
- Tests passing
- Ready for release"
```

**Separate commits (if --separate-commits flag):**
```bash
git add -A
git commit -m "feat: {description}"

# Stage only docs changes if they exist
git add AGENTS.md README.md CHANGELOG.md .env.example
git commit -m "docs: update for {feature}"
```

### 5.2 Push
```bash
git push origin $(git branch --show-current)
```

### 5.3 Create/Update PR
```bash
gh pr list --head $(git branch --show-current) --state open
```

**Create new PR:**
```bash
gh pr create --title "feat: {description}" --body "## Changes..."
```

**Check for `--review-only`** - Stop if set.

## Phase 6: Release

### 6.1 Verify CI
```bash
gh pr checks $(gh pr list --head $(git branch --show-current) --state open -q '.[0].number')
```

Abort if failing.

### 6.2 Quick Validation
- No new debug statements
- Tests still pass

### 6.3 Approve and Merge

**Approval (optional):**
```bash
gh pr review <number> --approve
```

If approval fails because you can't approve your own PR, that's OK - proceed to merge anyway.

**Merge (required):**
```bash
gh pr merge <number> --squash --delete-branch
```

**Note:** `--squash` ensures all commits in the PR are merged into a single clean commit on main branch, preventing WIP commit clutter in git history.

### 6.4 Tag Release
```bash
git checkout main && git pull origin main
gh release create v{version} --generate-notes --title "Release v{version}"
```

## Error Handling

**Critical (stop execution):**
- Unresolvable merge conflicts
- CI failures
- Test failures that cannot be fixed
- Main branch with no changes

**Warnings (continue with report):**
- Code style violations requiring manual review
- Missing test coverage
- Documentation suggestions

## Success Criteria

Complete when:
- Branch validated and current
- Code review complete with findings documented
- Auto-fixes applied
- Tests passing (or skipped with reason)
- Documentation updated appropriately
- PR created/updated
- PR approved and merged (unless `--review-only`)
- Release tag created (unless `--review-only`)

## Examples

### Full Release
```
/review-and-release
```

### Review Only
```
/review-and-release --review-only
```

### Skip Tests
```
/review-and-release --skip-tests
```

### Separate Commits
```
/review-and-release --separate-commits
```

### Dry Run
```
/review-and-release --dry-run
```
