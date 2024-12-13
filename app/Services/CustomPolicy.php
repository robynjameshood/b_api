<?php

namespace App\Services;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policies\Basic;

class CustomPolicy extends Basic
{
    public function configure()
    {
        //parent::configure();
        $this
            ->addDirective(Directive::BASE, Keyword::SELF)
            ->addDirective(Directive::CONNECT, Keyword::SELF)
            ->addDirective(Directive::DEFAULT, Keyword::SELF)
            ->addDirective(Directive::FORM_ACTION, Keyword::SELF)
            ->addDirective(Directive::IMG, Keyword::SELF)
            ->addDirective(Directive::MEDIA, Keyword::SELF)
            ->addDirective(Directive::OBJECT, Keyword::NONE)
            ->addDirective(Directive::SCRIPT, Keyword::SELF)
            ->addDirective(Directive::STYLE, Keyword::SELF)
            ->addNonceForDirective(Directive::SCRIPT);

        $this->addDirective(Directive::STYLE, ['fonts.googleapis.com','unsafe-inline'])
            ->addDirective(Directive::SCRIPT, ['unsafe-inline'])
            ->addDirective(Directive::FONT, ['self', 'fonts.gstatic.com', 'fonts.googleapis.com'])
            ->addDirective(Directive::IMG, ['data:']);
    }
}
