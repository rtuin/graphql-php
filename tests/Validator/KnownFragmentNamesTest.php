<?php
namespace GraphQL\Validator;

use GraphQL\Validator\Rules\KnownFragmentNames;
use GraphQL\FormattedError;
use GraphQL\Language\SourceLocation;

class KnownFragmentNamesTest extends TestCase
{
    // Validate: Known fragment names

    public function testKnownFragmentNamesAreValid()
    {
        $this->expectPassesRule(new KnownFragmentNames, '
      {
        human(id: 4) {
          ...HumanFields1
          ... on Human {
            ...HumanFields2
          }
        }
      }
      fragment HumanFields1 on Human {
        name
        ...HumanFields3
      }
      fragment HumanFields2 on Human {
        name
      }
      fragment HumanFields3 on Human {
        name
      }
        ');
    }

    public function testUnknownFragmentNamesAreInvalid()
    {
        $this->expectFailsRule(new KnownFragmentNames, '
      {
        human(id: 4) {
          ...UnknownFragment1
          ... on Human {
            ...UnknownFragment2
          }
        }
      }
      fragment HumanFields on Human {
        name
        ...UnknownFragment3
      }
        ', [
            $this->undefFrag('UnknownFragment1', 4, 14),
            $this->undefFrag('UnknownFragment2', 6, 16),
            $this->undefFrag('UnknownFragment3', 12, 12)
        ]);
    }

    private function undefFrag($fragName, $line, $column)
    {
        return new FormattedError(
            "Undefined fragment $fragName.",
            [new SourceLocation($line, $column)]
        );
    }
}
