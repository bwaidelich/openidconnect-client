<?php
declare(strict_types=1);
namespace Flownative\OpenIdConnect\Client\Authentication;

use Flownative\OpenIdConnect\Client\AuthenticationException;
use Flownative\OpenIdConnect\Client\ConnectionException;
use Flownative\OpenIdConnect\Client\IdentityToken;
use Flownative\OpenIdConnect\Client\OpenIdConnectClient;
use Flownative\OpenIdConnect\Client\ServiceException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authentication\Provider\AbstractProvider;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Flow\Security\Exception\InvalidAuthenticationStatusException;
use Neos\Flow\Security\Exception\NoSuchRoleException;
use Neos\Flow\Security\Exception\UnsupportedAuthenticationTokenException;
use Neos\Flow\Security\Policy\PolicyService;
use Psr\Log\LoggerInterface;

final class OpenIdConnectProvider extends AbstractProvider
{
    /**
     * @Flow\Inject
     * @var PolicyService
     */
    protected $policyService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @return array
     */
    public function getTokenClassNames(): array
    {
        return [OpenIdConnectToken::class];
    }

    /**
     * @param TokenInterface $authenticationToken
     * @throws AuthenticationException
     * @throws ConnectionException
     * @throws InvalidAuthenticationStatusException
     * @throws NoSuchRoleException
     * @throws ServiceException
     * @throws UnsupportedAuthenticationTokenException
     */
    public function authenticate(TokenInterface $authenticationToken): void
    {
        if (!$authenticationToken instanceof OpenIdConnectToken) {
            throw new UnsupportedAuthenticationTokenException(sprintf('The OpenID Connect authentication provider cannot authenticate the given token of type %s.', get_class($authenticationToken)), 1559805996);
        }
        if (!isset($this->options['roles'])) {
            throw new \RuntimeException(sprintf('Missing "roles" option in the configuration of OpenID Connect authentication provider'), 1559806095);
        }
        if (!isset($this->options['serviceName'])) {
            throw new \RuntimeException(sprintf('Missing "serviceName" option in the configuration of OpenID Connect authentication provider'), 1561480057);
        }
        if (!isset($this->options['accountIdentifierTokenValueName'])) {
            $this->options['accountIdentifierTokenValueName'] = 'sub';
        }
        $serviceName = $this->options['serviceName'];
        $jwtCookieName = $serviceName . '-jwt';
        try {
            $jwks = (new OpenIdConnectClient($serviceName))->getJwks();
            $identityToken = $authenticationToken->extractIdentityTokenFromRequest($jwtCookieName);
            if (!$identityToken->hasValidSignature($jwks)) {
                throw new SecurityException(sprintf('Open ID Connect: The identity token provided by the OIDC provider had an invalid signature'), 1561479176);
            }
        } catch (SecurityException $exception) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            $this->logger->notice(sprintf('OpenID Connect: The authentication provider caught an exception: %s', $exception->getMessage()));
            return;
        }

        if ($identityToken->isExpiredAt(new \DateTimeImmutable())) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_NEEDED);
            $this->logger->info(sprintf('OpenID Connect: The JWT token "%s" is expired, need to re-authenticate', $identityToken->values[$this->options['accountIdentifierTokenValueName']]));
            return;
        }

        if (!isset($identityToken->values[$this->options['accountIdentifierTokenValueName']])) {
            throw new AuthenticationException(sprintf('Open ID Connect: The identity token provided by the OIDC provider contained no "%s" value, which is needed as an account identifier', $this->options['accountIdentifierTokenValueName']), 1560267246);
        }

        $account = $this->createTransientAccount($identityToken->values[$this->options['accountIdentifierTokenValueName']], $this->options['roles'], $identityToken->asJwt());
        $authenticationToken->setAccount($account);
        $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);

        $this->logger->debug(sprintf('OpenID Connect: Successfully authenticated account "%s" with authentication provider %s.', $account->getAccountIdentifier(), $account->getAuthenticationProviderName()));
    }

    /**
     * @param string $accountIdentifier
     * @param array $roleIdentifiers
     * @param string $jwt
     * @return Account
     * @throws NoSuchRoleException
     */
    private function createTransientAccount(string $accountIdentifier, array $roleIdentifiers, string $jwt): Account
    {
        $account = new Account();
        $account->setAccountIdentifier($accountIdentifier);
        foreach ($roleIdentifiers as $roleIdentifier) {
            $account->addRole($this->policyService->getRole($roleIdentifier));
        }
        $account->setAuthenticationProviderName($this->name);
        $account->setCredentialsSource(IdentityToken::fromJwt($jwt));
        return $account;
    }
}
