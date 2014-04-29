<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Content\Types\Rlp\Mapper;


use PHPCR\NodeInterface;
use PHPCR\PropertyInterface;
use PHPCR\SessionInterface;
use PHPCR\Util\PathHelper;
use Sulu\Component\Content\Exception\ResourceLocatorAlreadyExistsException;
use Sulu\Component\Content\Exception\ResourceLocatorMovedException;
use Sulu\Component\Content\Exception\ResourceLocatorNotFoundException;
use Sulu\Component\Content\Types\Rlp\ResourceLocatorInformation;
use Sulu\Component\PHPCR\SessionManager\SessionManagerInterface;
use DateTime;

class PhpcrMapper extends RlpMapper
{

    /**
     * @var SessionManagerInterface
     */
    private $sessionManager;

    /**
     * @param SessionManagerInterface $sessionManager
     */
    function __construct(SessionManagerInterface $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    /**
     * creates a new route for given path
     * @param NodeInterface $contentNode reference node
     * @param string $path path to generate
     * @param string $webspaceKey key of webspace
     * @param string $languageCode
     * @param string $segmentKey
     */
    public function save(NodeInterface $contentNode, $path, $webspaceKey, $languageCode, $segmentKey = null)
    {
        $routes = $this->getRoutes($webspaceKey, $languageCode, $segmentKey);

        // check if route already exists
        if ($this->checkResourceLocator($routes, $path, $contentNode)) {
            return;
        }

        // create root recursive
        $routePath = explode('/', ltrim($path, '/'));
        $node = $routes;
        foreach ($routePath as $path) {
            if ($path != '') {
                if ($node->hasNode($path)) {
                    $node = $node->getNode($path);
                } else {
                    $node = $node->addNode($path, 'nt:unstructured');
                }
            }
        }

        $node->addMixin('sulu:path');
        $node->setProperty('sulu:content', $contentNode);
        $node->setProperty('sulu:history', false);
        $node->setProperty('sulu:created', new DateTime());
    }

    /**
     * returns path for given contentNode
     * @param NodeInterface $contentNode reference node
     * @param string $webspaceKey key of portal
     * @param string $languageCode
     * @param string $segmentKey
     *
     * @throws \Sulu\Component\Content\Exception\ResourceLocatorNotFoundException
     *
     * @return string path
     */
    public function loadByContent(NodeInterface $contentNode, $webspaceKey, $languageCode, $segmentKey = null)
    {
        $result = null;
        $result = $this->loadPathNodeByContent(
            $contentNode,
            function ($resourceLocator, \PHPCR\NodeInterface $node) use (&$result) {
                if (false === $node->getPropertyValue('sulu:history') && false !== $resourceLocator) {
                    return $resourceLocator;
                } else {
                    return false;
                }
            },
            $webspaceKey,
            $languageCode,
            $segmentKey
        );

        if ($result !== null) {
            return $result;
        } else {
            throw new ResourceLocatorNotFoundException();
        }
    }

    /**
     * return phpcr node path for given contentNode
     * @param NodeInterface $contentNode
     * @param callable $callback will be called foreach phpcr node (returns it true loop will be break)
     * @param string $webspaceKey
     * @param string $languageCode
     * @param string $segmentKey
     *
     * @return \PHPCR\NodeInterface
     */
    private function loadPathNodeByContent(
        NodeInterface $contentNode,
        $callback,
        $webspaceKey,
        $languageCode,
        $segmentKey = null
    )
    {
        // search for references with name 'content'
        foreach ($contentNode->getReferences('sulu:content') as $ref) {
            if ($ref instanceof \PHPCR\PropertyInterface) {
                $node = $ref->getParent();
                $resourceLocator = $this->getResourceLocator(
                    $ref->getParent()->getPath(),
                    $webspaceKey,
                    $languageCode,
                    $segmentKey
                );
                $result = $callback($resourceLocator, $node);
                if (false !== $result) {
                    return $result;
                }
            }
        }
        return null;
    }

    /**
     * returns path for given contentNode
     * @param string $uuid uuid of contentNode
     * @param string $webspaceKey key of portal
     * @param string $languageCode
     * @param string $segmentKey
     *
     * @throws \Sulu\Component\Content\Exception\ResourceLocatorNotFoundException
     *
     * @return string path
     */
    public function loadByContentUuid($uuid, $webspaceKey, $languageCode, $segmentKey = null)
    {
        $session = $this->sessionManager->getSession();
        $contentNode = $session->getNodeByIdentifier($uuid);

        return $this->loadByContent($contentNode, $webspaceKey, $languageCode, $segmentKey);
    }

    /**
     * {@inheritdoc}
     */
    public function loadHistoryByContentUuid($uuid, $webspaceKey, $languageCode, $segmentKey = null)
    {
        // get content node
        $session = $this->sessionManager->getSession();
        $contentNode = $session->getNodeByIdentifier($uuid);

        // get current path node
        $pathNode = $this->loadPathNodeByContent(
            $contentNode,
            function ($resourceLocator, \PHPCR\NodeInterface $node) use (&$result) {
                if (false === $node->getPropertyValue('sulu:history') && false !== $resourceLocator) {
                    return $node;
                } else {
                    return false;
                }
            },
            $webspaceKey,
            $languageCode,
            $segmentKey
        );

        // iterate over history of path node
        $result = array();
        $this->loadPathNodeByContent(
            $pathNode,
            function ($resourceLocator, NodeInterface $node) use (&$result) {
                if (false !== $resourceLocator) {
                    // add resourceLocator
                    $result[] = new ResourceLocatorInformation(
                        //                backward compability
                        $resourceLocator, $node->getPropertyValueWithDefault('sulu:created', new DateTime())
                    );
                }
                return false;
            },
            $webspaceKey,
            $languageCode,
            $segmentKey
        );

        // sort history descending
        usort(
            $result,
            function (ResourceLocatorInformation $item1, ResourceLocatorInformation $item2) {
                return $item1->getCreated() < $item2->getCreated();
            }
        );

        return $result;
    }

    /**
     * returns the uuid of referenced content node
     * @param string $resourceLocator requested RL
     * @param string $webspaceKey key of portal
     * @param string $languageCode
     * @param string $segmentKey
     *
     * @throws \Sulu\Component\Content\Exception\ResourceLocatorMovedException resourceLocator has been moved
     * @throws \Sulu\Component\Content\Exception\ResourceLocatorNotFoundException resourceLocator not found or has no content reference
     * @return string uuid of content node
     */
    public function loadByResourceLocator($resourceLocator, $webspaceKey, $languageCode, $segmentKey = null)
    {
        $resourceLocator = ltrim($resourceLocator, '/');

        $routes = $this->getRoutes($webspaceKey, $languageCode, $segmentKey);
        if (!$routes->hasNode($resourceLocator) && $resourceLocator !== '') {
            throw new ResourceLocatorNotFoundException();
        }

        if ($resourceLocator !== '') {
            $route = $routes->getNode($resourceLocator);
        } else {
            $route = $routes;
        }

        if ($route->hasProperty('sulu:content') && $route->hasProperty('sulu:history')) {
            if (!$route->getPropertyValue('sulu:history')) {
                /** @var NodeInterface $content */
                $content = $route->getPropertyValue('sulu:content');

                return $content->getIdentifier();
            } else {
                // get path from history node
                /** @var NodeInterface $realPath */
                $realPath = $route->getPropertyValue('sulu:content');

                throw new ResourceLocatorMovedException(
                    $this->getResourceLocator($realPath->getPath(), $webspaceKey, $languageCode, $segmentKey),
                    $realPath->getIdentifier()
                );
            }
        } else {
            throw new ResourceLocatorNotFoundException();
        }
    }

    /**
     * checks if given path is unique
     * @param string $path
     * @param string $webspaceKey key of portal
     * @param string $languageCode
     * @param string $segmentKey
     * @return bool
     */
    public function unique($path, $webspaceKey, $languageCode, $segmentKey = null)
    {
        $routes = $this->getRoutes($webspaceKey, $languageCode, $segmentKey);

        return $this->isUnique($routes, $path);
    }

    /**
     * returns a unique path with "-1" if necessary
     * @param string $path
     * @param string $webspaceKey key of portal
     * @param string $languageCode
     * @param string $segmentKey
     * @return string
     */
    public function getUniquePath($path, $webspaceKey, $languageCode, $segmentKey = null)
    {
        $routes = $this->getRoutes($webspaceKey, $languageCode, $segmentKey);

        if ($this->isUnique($routes, $path)) {
            // path is already unique
            return $path;
        } else {
            // append -
            $path .= '-';
            // init counter
            $i = 1;
            // while $path-$i is not unique raise counter
            while (!$this->isUnique($routes, $path . $i)) {
                $i++;
            }

            // result is unique
            return $path . $i;
        }
    }

    /**
     * creates a new resourcelocator and creates the correct history
     * @param string $src old resource locator
     * @param string $dest new resource locator
     * @param string $webspaceKey key of portal
     * @param string $languageCode
     * @param string $segmentKey
     *
     * @throws \Sulu\Component\Content\Exception\ResourceLocatorMovedException
     * @throws \Sulu\Component\Content\Exception\ResourceLocatorNotFoundException
     */
    public function move($src, $dest, $webspaceKey, $languageCode, $segmentKey = null)
    {
        // get abs path
        $absDestPath = $this->getPath($dest, $webspaceKey, $languageCode, $segmentKey);
        $absSrcPath = $this->getPath($src, $webspaceKey, $languageCode, $segmentKey);

        // init session
        $session = $this->sessionManager->getSession();
        $rootNode = $session->getRootNode();
        $workspace = $session->getWorkspace();
        $routes = $this->getRoutes($webspaceKey, $languageCode, $segmentKey);

        $routeNode = $routes->getNode(ltrim($src, '/'));
        if (!$routeNode->hasProperty('sulu:content')) {
            throw new ResourceLocatorNotFoundException();
        } elseif ($routeNode->getPropertyValue('sulu:history')) {
            $realPath = $routeNode->getPropertyValue('sulu:content');

            throw new ResourceLocatorMovedException(
                $this->getResourceLocator($realPath->getPath(), $webspaceKey, $languageCode, $segmentKey),
                $realPath->getIdentifier()
            );
        }

        $contentNode = $routeNode->getPropertyValue('sulu:content');

        // check if route already exists
        if ($this->checkResourceLocator($routes, $dest, $contentNode)) {
            return;
        }

        // create parent node for dest path
        $parentAbsDestPath = PathHelper::normalizePath($absDestPath . '/..');
        $this->createRecursive($parentAbsDestPath, $rootNode);
        $session->save();

        // copy route to new
        $workspace->copy($absSrcPath, $absDestPath);
        $destNode = $routes->getNode(ltrim($dest, '/'));
        $destNode->setProperty('sulu:created', new DateTime());

        // change old route node to history
        $this->changePathToHistory($routeNode, $session, $absSrcPath, $absDestPath);

        // get all old routes (in old route tree)
        $qm = $workspace->getQueryManager();
        $sql = "SELECT *
                FROM [sulu:path]
                WHERE ISDESCENDANTNODE('" . $absSrcPath . "')";

        $query = $qm->createQuery($sql, 'JCR-SQL2');
        $result = $query->execute();

        /** @var NodeInterface $node */
        foreach ($result->getNodes() as $node) {
            // FIXME move in SQL statement?
            if ($node->getPath() != $absDestPath) {
                $this->changePathToHistory($node, $session, $absSrcPath, $absDestPath);
            }
        }
    }

    /**
     * returns resource locator for parent node
     * @param string $uuid
     * @param string $webspaceKey
     * @param string $languageCode
     * @param string $segmentKey
     * @return string
     */
    public function getParentPath($uuid, $webspaceKey, $languageCode, $segmentKey = null)
    {
        $session = $this->sessionManager->getSession();
        $contentNode = $session->getNodeByIdentifier($uuid);
        $parentNode = $contentNode->getParent();

        return $this->loadByContent($parentNode, $webspaceKey, $languageCode, $segmentKey);
    }

    /**
     * create a node recursively
     * @param string $path path to node
     * @param NodeInterface $rootNode base node to begin
     */
    private function createRecursive($path, $rootNode)
    {
        $pathParts = explode('/', ltrim($path, '/'));
        $curNode = $rootNode;
        for ($i = 0; $i < sizeof($pathParts); $i++) {
            if ($curNode->hasNode($pathParts[$i])) {
                $curNode = $curNode->getNode($pathParts[$i]);
            } else {
                $curNode = $curNode->addNode($pathParts[$i]);
                $curNode->addMixin('mix:referenceable');
            }
        }
    }

    private function changePathToHistory(NodeInterface $node, SessionInterface $session, $absSrcPath, $absDestPath)
    {
        // get new path node
        $relPath = str_replace($absSrcPath, '', $node->getPath());
        $newPath = PathHelper::normalizePath($absDestPath . $relPath);
        $newPathNode = $session->getNode($newPath);

        // set history to true and set content to new path
        $node->setProperty('sulu:content', $newPathNode);
        $node->setProperty('sulu:history', true);

        // get referenced history
        /** @var PropertyInterface $property */
        foreach ($node->getReferences('sulu:content') as $property) {
            $property->getParent()->setProperty('sulu:content', $newPathNode);
        }
    }

    private function checkResourceLocator(NodeInterface $routes, $resourceLocator, $contentNode)
    {
        if (!$this->isUnique($routes, $resourceLocator)) {
            $routeNode = $routes->getNode(ltrim($resourceLocator, '/'));
            if ($routeNode->hasProperty('sulu:content') &&
                $routeNode->getPropertyValue('sulu:content') == $contentNode
            ) {
                // route already exists and referenced on contentNode
                return true;
            } else {
                throw new ResourceLocatorAlreadyExistsException();
            }
        }

        return false;
    }

    /**
     * check if path is unique from given $root node
     * @param NodeInterface $root route node
     * @param string $path requested path
     * @return bool path is unique
     */
    private function isUnique(NodeInterface $root, $path)
    {
        // check if root has node
        return !$root->hasNode(ltrim($path, '/'));
    }

    /**
     * returns base node of routes from phpcr
     * @param string $webspaceKey current session
     * @param string $languageCode
     * @param string $segmentKey
     * @return \PHPCR\NodeInterface base node of routes
     */
    private function getRoutes($webspaceKey, $languageCode, $segmentKey)
    {
        // trailing slash
        return $this->sessionManager->getRouteNode($webspaceKey, $languageCode, $segmentKey);
    }

    /**
     * returns the abspath
     * @param string $relPath
     * @param string $webspaceKey
     * @param string $languageCode
     * @param string $segmentKey
     * @return string
     */
    private function getPath($relPath, $webspaceKey, $languageCode, $segmentKey)
    {
        $basePath = $this->getRoutes($webspaceKey, $languageCode, $segmentKey)->getPath();
        return '/' . ltrim($basePath, '/') . ($relPath !== '' ? '/' . ltrim($relPath, '/') : '');
    }

    /**
     * @param $path
     * @param $webspaceKey
     * @param string $languageCode
     * @param string $segmentKey
     * @return string
     */
    private function getResourceLocator($path, $webspaceKey, $languageCode, $segmentKey)
    {
        $basePath = $this->getRoutes($webspaceKey, $languageCode, $segmentKey)->getPath();
        if ($path === $basePath) {
            return '/';
        }
        if (false !== strpos($path, $basePath . '/')) {
            $result = str_replace($basePath . '/', '/', $path);
            if (0 === strpos($result, '/')) {
                return $result;
            }
        }
        return false;
    }
}