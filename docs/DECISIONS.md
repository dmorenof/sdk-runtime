# Design decisions

Decisions taken during the quality review that led to 2.0.0. Each entry says what was decided and why, so a later change can be judged against the reason and not only the result. Newest decisions go at the bottom.

## Rules adopted

| # | Rule | Reason |
| --- | --- | --- |
| R1 | A function takes at most 3 arguments, 4 in justified cases. More options travel in a configuration object. | Long argument lists are hard to read, easy to misorder and impossible to extend without breaking callers. |
| R2 | A class that only carries named options (a *configuration object*) may list its options in its constructor, up to 6, always used with named arguments. Splitting it further would hide options inside nested objects without making anything simpler (KISS). | The rule R1 exists to keep logic simple; a record of settings is not logic. |
| R3 | A method named `with…` must not modify the object it is called on. A method that changes state is named `set…`, `add…` or `use…`. | A name must not lie about what a method does. |
| R4 | One class, one reason to change (SRP). A class larger than about 300 lines, or a method larger than about 40 lines, is reviewed and split unless splitting makes it harder to follow. | Smaller units are easier to read, test and replace. |
| R5 | Internal helpers are `final` and marked `@internal`. The public surface is what `tests/Fixtures/public-api.txt` lists. | The public API stays small and every public name is a promise. |

## Decisions

Filled in as the review progresses.
