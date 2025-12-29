<?php

test('seo assistant provider exists', function () {
    expect(class_exists(\FortyQ\SeoAssistant\SeoAssistantServiceProvider::class))->toBeTrue();
});
