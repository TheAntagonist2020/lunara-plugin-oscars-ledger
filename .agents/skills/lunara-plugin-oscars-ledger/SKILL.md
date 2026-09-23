```markdown
# lunara-plugin-oscars-ledger Development Patterns

> Auto-generated skill from repository analysis

## Overview
This skill teaches you the development conventions and workflows used in the `lunara-plugin-oscars-ledger` TypeScript codebase. You'll learn how to structure files, write imports/exports, and follow testing patterns, enabling you to contribute code that fits seamlessly into the project.

## Coding Conventions

### File Naming
- Use **kebab-case** for all file names.
  - Example:  
    ```
    transaction-handler.ts
    ledger-entry.test.ts
    ```

### Import Style
- Use **relative imports** for referencing other modules within the project.
  - Example:
    ```typescript
    import { calculateBalance } from './balance-utils';
    ```

### Export Style
- Use **named exports** instead of default exports.
  - Example:
    ```typescript
    // In balance-utils.ts
    export function calculateBalance(entries: LedgerEntry[]): number { ... }

    // Usage
    import { calculateBalance } from './balance-utils';
    ```

### Commit Messages
- Commit messages are **freeform** (no enforced prefix or format).
- Average commit message length: ~44 characters.
  - Example:
    ```
    Add support for multi-currency transactions
    ```

## Workflows

### Adding a New Feature
**Trigger:** When implementing new functionality.
**Command:** `/add-feature`

1. Create a new file using kebab-case (e.g., `feature-name.ts`).
2. Write your feature using TypeScript, following the import/export conventions.
3. Add or update tests in a corresponding `*.test.ts` file.
4. Commit your changes with a clear, descriptive message.
5. Open a pull request for review.

### Writing and Running Tests
**Trigger:** When you need to verify code correctness.
**Command:** `/run-tests`

1. Create or update test files with the `.test.ts` suffix.
2. Write tests covering all new or changed functionality.
3. Run the test suite using your preferred TypeScript test runner.
4. Ensure all tests pass before committing.

### Refactoring Code
**Trigger:** When improving or restructuring existing code.
**Command:** `/refactor`

1. Identify code to refactor and plan changes.
2. Apply changes, maintaining existing coding conventions.
3. Update or add tests as needed to cover refactored code.
4. Run the test suite to confirm no regressions.
5. Commit with a message describing the refactor.

## Testing Patterns

- Test files use the `*.test.ts` naming convention and are placed alongside or near the code they test.
- The specific test framework is **unknown**, but standard TypeScript test runners (like Jest or Mocha) are likely compatible.
- Example test file:
  ```typescript
  // ledger-entry.test.ts
  import { calculateBalance } from './balance-utils';

  describe('calculateBalance', () => {
    it('returns correct sum for entries', () => {
      const entries = [{ amount: 10 }, { amount: -5 }];
      expect(calculateBalance(entries)).toBe(5);
    });
  });
  ```

## Commands
| Command       | Purpose                                      |
|---------------|----------------------------------------------|
| /add-feature  | Scaffold and implement a new feature         |
| /run-tests    | Run the project's test suite                 |
| /refactor     | Refactor existing code and update tests      |
```
