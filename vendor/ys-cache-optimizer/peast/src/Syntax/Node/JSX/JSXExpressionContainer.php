<?php
/**
 * This file is part of the Peast package
 *
 * (c) Marco Marchiò <marco.mm89@gmail.com>
 *
 * For the full copyright and license information refer to the LICENSE file
 * distributed with this source code
 */
namespace YangSheep\Cache\Vendor\Peast\Syntax\Node\JSX;

use YangSheep\Cache\Vendor\Peast\Syntax\Node\Node;

/**
 * A node that represents an expression container in JSX.
 *
 * @author Marco Marchiò <marco.mm89@gmail.com>
 */
class JSXExpressionContainer extends Node
{
    /**
     * Map of node properties
     *
     * @var array
     */
    protected $propertiesMap = array(
        "expression" => true
    );

    /**
     * The wrapped expression
     *
     * @var \YangSheep\Cache\Vendor\Peast\Syntax\Node\Expression|JSXEmptyExpression
     */
    protected $expression;

    /**
     * Returns the wrapped expression
     *
     * @return \YangSheep\Cache\Vendor\Peast\Syntax\Node\Expression|JSXEmptyExpression
     */
    public function getExpression()
    {
        return $this->expression;
    }

    /**
     * Sets the wrapped expression
     *
     * @param \YangSheep\Cache\Vendor\Peast\Syntax\Node\Expression|JSXEmptyExpression $expression Wrapped
     *                                                                     expression
     *
     * @return $this
     */
    public function setExpression($expression)
    {
        $this->assertType(
            $expression,
            array("Expression", "JSX\\JSXEmptyExpression")
        );
        $this->expression = $expression;
        return $this;
    }
}