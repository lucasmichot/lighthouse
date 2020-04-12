<?php

namespace Nuwave\Lighthouse\Schema\Directives;

use Closure;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\TypeExtensionNode;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Nuwave\Lighthouse\Exceptions\AuthenticationException;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\DefinedDirective;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Contracts\TypeExtensionManipulator;
use Nuwave\Lighthouse\Support\Contracts\TypeManipulator;

/**
 * @see \Illuminate\Auth\Middleware\Authenticate
 */
class GuardDirective extends BaseDirective implements FieldMiddleware, TypeManipulator, TypeExtensionManipulator, DefinedDirective
{
    /**
     * @var \Illuminate\Contracts\Auth\Factory
     */
    protected $auth;

    /**
     * @param  \Illuminate\Contracts\Auth\Factory  $auth
     */
    public function __construct(AuthFactory $auth)
    {
        $this->auth = $auth;
    }

    /**
     * @return string
     */
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'SDL'
"""
Run authentication through one or more guards.
This is run per field and may allow unauthenticated
users to still receive partial results.
"""
directive @guard(
  """
  Specify which guards to use, e.g. "api".
  When not defined, the default driver is used.
  """
  with: [String!]
) on FIELD_DEFINITION | OBJECT
SDL;
    }

    /**
     * Resolve the field directive.
     *
     * @param  \Nuwave\Lighthouse\Schema\Values\FieldValue  $fieldValue
     * @param  \Closure  $next
     * @return \Nuwave\Lighthouse\Schema\Values\FieldValue
     */
    public function handleField(FieldValue $fieldValue, Closure $next): FieldValue
    {
        $previousResolver = $fieldValue->getResolver();

        return $next(
            $fieldValue->setResolver(
                function ($root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($previousResolver) {
                    $this->authenticate(
                        (array) $this->directiveArgValue('with')
                    );

                    return $previousResolver($root, $args, $context, $resolveInfo);
                }
            )
        );
    }

    /**
     * Determine if the user is logged in to any of the given guards.
     *
     * @param  string[]  $guards
     * @return void
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    protected function authenticate(array $guards): void
    {
        if (empty($guards)) {
            $guards = [null];
        }

        foreach ($guards as $guard) {
            if ($this->auth->guard($guard)->check()) {
                $this->auth->shouldUse($guard);

                return;
            }
        }

        $this->unauthenticated($guards);
    }

    /**
     * Handle an unauthenticated user.
     *
     * @param  array<string|null>  $guards
     * @return void
     */
    protected function unauthenticated(array $guards): void
    {
        throw new AuthenticationException(
            AuthenticationException::UNAUTHENTICATED,
            $guards
        );
    }

    /**
     * @param  \Nuwave\Lighthouse\Schema\AST\DocumentAST  $documentAST
     * @param  \GraphQL\Language\AST\TypeDefinitionNode  $typeDefinition
     * @return void
     */
    public function manipulateTypeDefinition(DocumentAST &$documentAST, TypeDefinitionNode &$typeDefinition): void
    {
        ASTHelper::addDirectiveToFields($this->directiveNode, $typeDefinition);
    }

    /**
     * @param  \Nuwave\Lighthouse\Schema\AST\DocumentAST  $documentAST
     * @param  \GraphQL\Language\AST\TypeExtensionNode  $typeExtension
     * @return void
     */
    public function manipulateTypeExtension(DocumentAST &$documentAST, TypeExtensionNode &$typeExtension): void
    {
        ASTHelper::addDirectiveToFields($this->directiveNode, $typeExtension);
    }
}
