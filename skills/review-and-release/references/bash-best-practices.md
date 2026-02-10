# Bash Script Best Practices

Reference for reviewing bash scripts in this project.

## Non-negotiables from AGENTS.md

### Idempotency
Scripts must be safe to run multiple times:

```bash
# Good - check before creating
if [ ! -d "$DIR" ]; then
    mkdir -p "$DIR"
fi

# Good - use mkdir -p (idempotent by nature)
mkdir -p "$DIR"
```

### Explicit Outputs
Scripts must print:
- Next-step commands
- Health checks

```bash
# Good
setup_resource() {
    echo "✅ Resource created"
    echo ""
    echo "Next steps:"
    echo "  1. Run: verify_resource.sh"
    echo "  2. Check: curl http://localhost/health"
}
```

### Error Handling
Always use strict mode:

```bash
#!/bin/bash
set -euo pipefail

# -e: exit on error
# -u: exit on undefined variable
# -o pipefail: catch errors in pipes
```

## Security Checks

### No Hardcoded Secrets
```bash
# Bad
API_KEY="abc123"

# Good
API_KEY="${API_KEY:-}"
if [ -z "$API_KEY" ]; then
    echo "Error: API_KEY not set"
    exit 1
fi
```

### Variable Quoting
```bash
# Bad - word splitting issues
rm $file

# Good
rm "$file"
```

### Safe Temp Files
```bash
# Bad
temp="/tmp/myscript.$$"

# Good
temp=$(mktemp)
trap "rm -f $temp" EXIT
```

## Code Review Checklist

- [ ] Uses `set -euo pipefail`
- [ ] Variables are quoted
- [ ] No hardcoded secrets
- [ ] Idempotent operations
- [ ] Prints next steps
- [ ] Prints health checks
- [ ] Proper exit codes
- [ ] Trap for cleanup
