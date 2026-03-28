<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class RelatedPostsTagLinksCest
{
    public function relatedPostsContainLinkedTags(AcceptanceTester $I)
    {
        $I->amOnPage('/status/111');
        $I->seeElement('.related-posts a[href^="/tag/"]');
    }
}
