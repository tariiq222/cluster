import * as generated from './generated/cluster'
import type {
  UserAccount as GeneratedUserAccount,
  UserAccountCollection as GeneratedUserAccountCollection,
  UserAccountCreate,
} from './generated/cluster'
import { ApiError, requestInit, unwrap, unwrapWithEtag } from './http'

export type UserAccount = GeneratedUserAccount
export type UserAccountCollection = GeneratedUserAccountCollection
export type CreateUserAccountInput = UserAccountCreate
export type UserAccountAction =
  | 'activate'
  | 'unlock'
  | 'disable'
  | 'archive'
  | 'revoke-sessions'
  | 'force-password-change'

export async function listUserAccounts(token: string): Promise<UserAccountCollection> {
  return unwrap<UserAccountCollection>(
    await generated.listUserAccounts({ limit: 100 }, requestInit(token)),
  )
}

export async function getUserAccount(token: string, accountId: string): Promise<UserAccount> {
  return unwrap<UserAccount>(await generated.getUserAccount(accountId, requestInit(token)))
}

export async function createUserAccount(
  token: string,
  input: CreateUserAccountInput,
): Promise<UserAccount> {
  return unwrap<UserAccount>(
    await generated.createUserAccount(input, requestInit(token, { command: true, idempotency: 'identity-account' })),
  )
}

export async function transitionUserAccount(
  token: string,
  accountId: string,
  action: UserAccountAction,
  reason?: string,
): Promise<UserAccount> {
  const { etag } = unwrapWithEtag<UserAccount>(
    await generated.getUserAccount(accountId, requestInit(token)),
  )
  if (!etag) {
    throw new ApiError(502, {
      type: 'about:blank',
      title: 'Missing account version',
      status: 502,
    })
  }

  return unwrap<UserAccount>(
    await generated.transitionUserAccount(
      accountId,
      action,
      reason ? { reason } : undefined,
      requestInit(token, { command: true, idempotency: `identity-${action}`, ifMatch: etag }),
    ),
  )
}
