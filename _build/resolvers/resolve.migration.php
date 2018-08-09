<?php
set_time_limit(0);

if (!function_exists('replaceIdWithUuidOnElements')) {
    /**
     * @param modX $modx
     * @param array $cache
     * @param array $data
     */
    function replaceIdWithUuidOnElements($modx, &$cache, &$data)
    {
        foreach ($data as &$dropZone) {
            if(!is_array($dropZone)) continue;

            foreach ($dropZone as &$element) {
                $elementId = intval($element['widget']);

                if (!isset($cache[$elementId])) {
                    /** @var FredElement $fredElement */
                    $fredElement = $modx->getObject('FredElement', ['id' => $elementId]);
                    if ($fredElement) {
                        $cache[$elementId] = $fredElement->uuid;
                        $element['widget'] = $cache[$elementId];
                    }
                } else {
                    $element['widget'] = $cache[$elementId];
                }

                replaceIdWithUuidOnElements($modx, $cache, $element['children']);
            }
        }
    }
}

if (!function_exists('iterateElements')) {
    /**
     * @param modX $modx
     * @param array $cache
     * @param array $data
     */
    function iterateElements($modx, &$cache, &$data)
    {
        foreach ($data as &$element) {
            $elementId = $element['widget'];

            if (!isset($elements[$elementId])) {
                /** @var FredElement $fredElement */
                $fredElement = $modx->getObject('FredElement', ['id' => $elementId]);
                if ($fredElement) {
                    $cache[$elementId] = $fredElement->uuid;
                    $element['widget'] = $cache[$elementId];
                }
            } else {
                $element['widget'] = $cache[$elementId];
            }

            replaceIdWithUuidOnElements($modx, $cache, $element['children']);
        }
    }
}

if ($object->xpdo) {
    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_UPGRADE:
            /** @var modX $modx */
            $modx =& $object->xpdo;

            // http://forums.modx.com/thread/88734/package-version-check#dis-post-489104
            $c = $modx->newQuery('transport.modTransportPackage');
            $c->where(array(
                'workspace' => 1,
                "(SELECT
                        `signature`
                      FROM {$modx->getTableName('modTransportPackage')} AS `latestPackage`
                      WHERE `latestPackage`.`package_name` = `modTransportPackage`.`package_name`
                      ORDER BY
                         `latestPackage`.`version_major` DESC,
                         `latestPackage`.`version_minor` DESC,
                         `latestPackage`.`version_patch` DESC,
                         IF(`release` = '' OR `release` = 'ga' OR `release` = 'pl','z',`release`) DESC,
                         `latestPackage`.`release_index` DESC
                      LIMIT 1,1) = `modTransportPackage`.`signature`",
            ));
            $c->where(array(
                'modTransportPackage.package_name' => 'fred',
                'installed:IS NOT' => null
            ));

            /** @var modTransportPackage $oldPackage */
            $oldPackage = $modx->getObject('transport.modTransportPackage', $c);

            $modelPath = $modx->getOption('fred.core_path',null,$modx->getOption('core_path').'components/fred/').'model/';
            $modx->addPackage('fred',$modelPath);

            if ($oldPackage && $oldPackage->compareVersion('1.0.0-beta4', '>')) {
                $optionsMap = [];

                $rootCategory = (int)$modx->getOption('fred.elements_category_id');

                $c = $modx->newQuery('modCategory');
                $c->where([
                    'parent' => $rootCategory
                ]);

                /** @var \modCategory[] $categories */
                $categories = $modx->getIterator('modCategory', $c);
                foreach ($categories as $category) {
                    $newCategory = $modx->newObject('FredElementCategory');
                    $newCategory->set('name', $category->category);
                    $newCategory->set('rank', $category->rank);
                    $newCategory->save();

                    /** @var \modChunk[] $chunks */
                    $chunks = $modx->getIterator('modChunk', ['category' => $category->id]);
                    foreach ($chunks as $chunk) {
                        $matches = [];
                        preg_match('/image:([^\n]+)\n?/', $chunk->description, $matches);

                        $image = '';
                        $options = [];
                        $description = $chunk->description;

                        if (count($matches) == 2) {
                            $image = $matches[1];
                            $description = str_replace($matches[0], '', $description);
                        }

                        $matches = [];
                        preg_match('/options:([^\n]+)\n?/', $description, $matches);


                        $optionSet = 0;
                        if (count($matches) == 2) {
                            if (!empty($optionsMap[$matches[1]])) {
                                $optionSet = $optionsMap[$matches[1]];
                            } else {
                                /** @var modChunk $options */
                                $options = $modx->getObject('modChunk', ['name' => $matches[1]]);
                                if ($options) {
                                    $optionSetObject = $modx->newObject('FredElementOptionSet');
                                    $optionSetObject->set('name', $matches[1]);
                                    $optionSetObject->set('data', json_decode($options->content, true));
                                    $optionSetObject->set('complete', true);
                                    $optionSetObject->save();

                                    $optionsMap[$matches[1]] = $optionSetObject->id;
                                    $optionSet = $optionSetObject->id;
                                }
                            }

                            $description = str_replace($matches[0], '', $description);
                        }

                        $newElement = $modx->newObject('FredElement');
                        $newElement->set('id', $chunk->id);
                        $newElement->set('name', $chunk->name);
                        $newElement->set('description', $description);
                        $newElement->set('image', $image);
                        $newElement->set('content', $chunk->content);
                        $newElement->set('category', $newCategory->id);
                        $newElement->set('option_set', $optionSet);
                        $newElement->save();
                    }

                }

                $globalRteConfig = $modx->getOption('fred.rte_config');
                if (!empty($globalRteConfig)) {
                    $config = $modx->getChunk($globalRteConfig);
                    $config = json_decode($config, true);
                    
                    if (!empty($config)) {
                        $fredRTEConfig = $modx->newObject('FredElementRTEConfig');
                        $fredRTEConfig->set('name', 'TinyMCE');
                        $fredRTEConfig->set('data', $config);
                        $fredRTEConfig->save();
                    }
                }

                $fredTemplates = $modx->getOption('fred.template_ids');
                $fredTemplates = explode(',', $fredTemplates);

                $cache = [];

                if (!empty($fredTemplates)) {
                    /** @var modResource[] $fredResources */
                    $fredResources = $modx->getIterator('modResource', ['template:IN' => $fredTemplates]);

                    foreach ($fredResources as $resource) {
                        $data = $resource->getProperty('data', 'fred');
                        $this->replaceIdWithUuidOnElements($modx, $cache, $data);
                        $resource->setProperty('data', $data, 'fred');
                        $resource->save();
                    }
                }

                /** @var FredBlueprint[] $blueprints */
                $blueprints = $modx->getIterator('FredBlueprint');
                foreach ($blueprints as $blueprint) {
                    $data = $blueprint->get('data');
                    $complete = $blueprint->get('complete');

                    if ($complete === true) {
                        $this->replaceIdWithUuidOnElements($modx, $cache, $data);
                    } else {
                        $this->iterateElements($modx, $cache, $data);
                    }

                    $blueprint->set('data', $data);
                    $blueprint->save();
                }
            }

            break;
    }
}
return true;