# Testing

> **Assert in your tests what the audit recorded**

`DH\AuditorBundle\Test\AuditAssertionsTrait` reads the audit entries written by the code under test, in a
`KernelTestCase` or a `WebTestCase`:

```php
use App\Entity\User;
use DH\AuditorBundle\Test\AuditAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RenameUserTest extends KernelTestCase
{
    use AuditAssertionsTrait;

    public function testRename(): void
    {
        // ... rename user 42 from "John" to "Jane" and flush

        self::assertAuditCount(1, User::class, 42, 'update');
        self::assertAuditFieldChanged(User::class, 42, 'name', 'John', 'Jane');
    }
}
```

| Method | Description |
|---|---|
| `getAuditEntries(string $entity, int\|string\|null $id = null, ?string $type = null)` | Entries of an entity, most recent first, optionally of one object and of one type (`insert`, `update`, `remove`, `associate`, `dissociate`) |
| `assertAuditCount(int $expected, string $entity, int\|string\|null $id = null, ?string $type = null)` | Number of these entries |
| `assertAuditFieldChanged(string $entity, int\|string $id, string $field, mixed $old, mixed $new)` | The most recent entry of the object that changed `$field` recorded it from `$old` to `$new` |

- The entries are read from the audit tables of the Doctrine provider of the container, without the role checks nor
  the `read_scope`: a test does not need a logged-in user to read them.
- The values are compared as they are stored in the diff: a `#[DiffLabel]` value is an array with `value` and
  `label`, a `#[Mask]` value is masked, a date is a string.
- The audit entries of a flush are written when its database transaction commits: assert after the flush.
