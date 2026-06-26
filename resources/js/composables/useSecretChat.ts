import { router, useHttp } from '@inertiajs/vue3';
import { computed, ref, shallowRef, toRaw, watch } from 'vue';
import type { Ref } from 'vue';
import type { CurrentRoom } from '@/composables/useMessengerStore';
import { store as storeSecretChatKey } from '@/routes/secret-chats/key';
import type { SecretChatParticipant, SecretChatProps } from '@/types';

type StoredSecretKeyPair = {
    id: string;
    privateKey: CryptoKey;
    publicKey: JsonWebKey;
    fingerprint: string;
};

type EncryptedSecretMessage = {
    ciphertext: string;
    iv: string;
};

type DecryptedSecretMessage = {
    body: string;
    decrypted: boolean;
};

type SecretPublicKey = Required<Pick<JsonWebKey, 'crv' | 'kty' | 'x' | 'y'>>;

const secretKeyDatabaseName = 'padik-secret-chat-keys';
const secretKeyStoreName = 'keyPairs';

const canonicalJson = (value: unknown): string =>
    JSON.stringify(value, Object.keys(value as object).sort());

const normalizePublicKey = (key: JsonWebKey): SecretPublicKey => ({
    crv: String(key.crv),
    kty: String(key.kty),
    x: String(key.x),
    y: String(key.y),
});

const bytesToBase64 = (bytes: Uint8Array): string =>
    btoa(String.fromCharCode(...bytes));

const base64ToBytes = (value: string): Uint8Array =>
    Uint8Array.from(atob(value), (character) => character.charCodeAt(0));

const digestHex = async (value: string): Promise<string> => {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(value),
    );

    return [...new Uint8Array(digest)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
};

const openSecretKeyDatabase = (): Promise<IDBDatabase> =>
    new Promise((resolve, reject) => {
        const request = indexedDB.open(secretKeyDatabaseName, 1);

        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(secretKeyStoreName)) {
                request.result.createObjectStore(secretKeyStoreName, {
                    keyPath: 'id',
                });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () =>
            reject(request.error ?? new Error('Could not open IndexedDB.'));
    });

const readStoredSecretKeyPair = async (
    storageKey: string,
): Promise<StoredSecretKeyPair | null> => {
    const database = await openSecretKeyDatabase();

    return new Promise((resolve, reject) => {
        const transaction = database.transaction(
            secretKeyStoreName,
            'readonly',
        );
        const request = transaction
            .objectStore(secretKeyStoreName)
            .get(storageKey);

        transaction.oncomplete = () => database.close();
        transaction.onerror = () => {
            database.close();
            reject(transaction.error ?? new Error('Could not read key pair.'));
        };
        request.onsuccess = () =>
            resolve(
                (request.result as StoredSecretKeyPair | undefined) ?? null,
            );
        request.onerror = () =>
            reject(request.error ?? new Error('Could not read key pair.'));
    });
};

const storeSecretKeyPair = async (
    keyPair: StoredSecretKeyPair,
): Promise<void> => {
    const database = await openSecretKeyDatabase();

    return new Promise((resolve, reject) => {
        const transaction = database.transaction(
            secretKeyStoreName,
            'readwrite',
        );
        const request = transaction
            .objectStore(secretKeyStoreName)
            .put(keyPair);

        transaction.oncomplete = () => {
            database.close();
            resolve();
        };
        transaction.onerror = () => {
            database.close();
            reject(transaction.error ?? new Error('Could not store key pair.'));
        };
        request.onerror = () =>
            reject(request.error ?? new Error('Could not store key pair.'));
    });
};

export const formatSafetyNumber = (fingerprint: string | null): string =>
    fingerprint
        ? (fingerprint
              .slice(0, 40)
              .match(/.{1,4}/g)
              ?.join(' ') ?? '')
        : '';

export function useSecretChat(
    currentRoom: Ref<CurrentRoom | undefined>,
    secretChat: Ref<SecretChatProps | undefined>,
    currentUserId: number,
) {
    const participants = ref<SecretChatParticipant[]>([]);
    const privateKey = shallowRef<CryptoKey | null>(null);
    const publicKey = shallowRef<JsonWebKey | null>(null);
    const fingerprint = ref<string | null>(null);
    const sharedKey = ref<CryptoKey | null>(null);
    const safetyNumber = ref<string | null>(null);
    const status = ref('Preparing encrypted session...');
    const keyStorageError = ref<string | null>(null);

    const isSecretRoom = computed(() => currentRoom.value?.type === 'secret');
    const storageKey = computed(() => `secret-key-pair:user:${currentUserId}`);
    const currentParticipant = computed(() =>
        participants.value.find(
            (participant) => Number(participant.id) === currentUserId,
        ),
    );
    const peerParticipant = computed(() =>
        participants.value.find(
            (participant) => Number(participant.id) !== currentUserId,
        ),
    );

    const applyParticipant = (participant: SecretChatParticipant): void => {
        const index = participants.value.findIndex(
            (existingParticipant) =>
                Number(existingParticipant.id) === Number(participant.id),
        );

        if (index === -1) {
            participants.value.push(participant);

            return;
        }

        participants.value[index] = participant;
    };

    const ensureKeyPair = async (): Promise<void> => {
        if (privateKey.value && publicKey.value && fingerprint.value) {
            return;
        }

        try {
            const storedKeyPair = await readStoredSecretKeyPair(
                storageKey.value,
            );

            if (storedKeyPair) {
                const storedPublicKey = normalizePublicKey(
                    storedKeyPair.publicKey,
                );

                privateKey.value = storedKeyPair.privateKey;
                publicKey.value = storedPublicKey;
                fingerprint.value = await digestHex(
                    canonicalJson(storedPublicKey),
                );
                keyStorageError.value = null;

                return;
            }
        } catch (error) {
            keyStorageError.value =
                error instanceof Error
                    ? error.message
                    : 'Unknown storage error';
        }

        const keyPair = await crypto.subtle.generateKey(
            {
                name: 'ECDH',
                namedCurve: 'P-256',
            },
            false,
            ['deriveBits'],
        );

        privateKey.value = keyPair.privateKey;
        publicKey.value = await crypto.subtle.exportKey(
            'jwk',
            keyPair.publicKey,
        );
        const exportedPublicKey = normalizePublicKey(toRaw(publicKey.value));

        fingerprint.value = await digestHex(canonicalJson(exportedPublicKey));

        try {
            await storeSecretKeyPair({
                id: storageKey.value,
                privateKey: keyPair.privateKey,
                publicKey: exportedPublicKey,
                fingerprint: fingerprint.value,
            });
            keyStorageError.value = null;
        } catch (error) {
            keyStorageError.value =
                error instanceof Error
                    ? error.message
                    : 'Unknown storage error';
        }
    };

    const publishPublicKey = async (): Promise<void> => {
        if (!currentRoom.value || !publicKey.value || !fingerprint.value) {
            return;
        }

        await useHttp({
            public_key: normalizePublicKey(toRaw(publicKey.value)),
            fingerprint: fingerprint.value,
        }).post(storeSecretChatKey.url(currentRoom.value.slug), {
            onSuccess: (data) => {
                const response = data as {
                    public_key: JsonWebKey;
                    fingerprint: string;
                };

                applyParticipant({
                    id: currentUserId,
                    name: currentParticipant.value?.name ?? '',
                    public_key: response.public_key,
                    fingerprint: response.fingerprint,
                });
            },
        });
    };

    const deriveSharedKey = async (): Promise<void> => {
        const peer = peerParticipant.value;

        if (
            !privateKey.value ||
            !fingerprint.value ||
            !publicKey.value ||
            !peer?.public_key ||
            !peer.fingerprint
        ) {
            sharedKey.value = null;
            safetyNumber.value = null;
            status.value =
                'Waiting for the other user to open this secret chat.';

            return;
        }

        const peerPublicKey = await crypto.subtle.importKey(
            'jwk',
            peer.public_key,
            {
                name: 'ECDH',
                namedCurve: 'P-256',
            },
            false,
            [],
        );
        const sharedBits = await crypto.subtle.deriveBits(
            {
                name: 'ECDH',
                public: peerPublicKey,
            },
            privateKey.value,
            256,
        );
        const keyMaterial = await crypto.subtle.importKey(
            'raw',
            sharedBits,
            'HKDF',
            false,
            ['deriveKey'],
        );
        const safetySeed = [fingerprint.value, peer.fingerprint]
            .sort()
            .join(':');
        const salt = await crypto.subtle.digest(
            'SHA-256',
            new TextEncoder().encode(safetySeed),
        );

        sharedKey.value = await crypto.subtle.deriveKey(
            {
                name: 'HKDF',
                hash: 'SHA-256',
                salt,
                info: new TextEncoder().encode('padik-secret-chat-v1'),
            },
            keyMaterial,
            {
                name: 'AES-GCM',
                length: 256,
            },
            false,
            ['encrypt', 'decrypt'],
        );
        safetyNumber.value = await digestHex(
            [
                canonicalJson(normalizePublicKey(toRaw(publicKey.value))),
                canonicalJson(normalizePublicKey(peer.public_key)),
            ]
                .sort()
                .join(':'),
        );
        status.value = keyStorageError.value
            ? `End-to-end encrypted. Browser key storage failed: ${keyStorageError.value}.`
            : 'End-to-end encrypted. Compare the safety number before sharing sensitive information.';
    };

    const setup = async (): Promise<void> => {
        if (!isSecretRoom.value) {
            sharedKey.value = null;
            safetyNumber.value = null;

            return;
        }

        await ensureKeyPair();

        if (
            currentParticipant.value?.fingerprint !== fingerprint.value ||
            !currentParticipant.value?.public_key
        ) {
            await publishPublicKey();
        }

        await deriveSharedKey();
    };

    const reloadMetadata = (): Promise<void> =>
        new Promise((resolve) => {
            router.reload({
                only: ['secretChat'],
                onFinish: () => {
                    void setup().finally(resolve);
                },
            });
        });

    const synchronizeSender = async (
        senderFingerprint: string,
    ): Promise<void> => {
        if (peerParticipant.value?.fingerprint === senderFingerprint) {
            return;
        }

        await reloadMetadata();
    };

    const encryptMessage = async (
        body: string,
    ): Promise<EncryptedSecretMessage> => {
        if (!sharedKey.value) {
            throw new Error('Secret chat key is not ready.');
        }

        const iv = crypto.getRandomValues(new Uint8Array(12));
        const ciphertext = await crypto.subtle.encrypt(
            {
                name: 'AES-GCM',
                iv: iv as BufferSource,
            },
            sharedKey.value,
            new TextEncoder().encode(body),
        );

        return {
            ciphertext: bytesToBase64(new Uint8Array(ciphertext)),
            iv: bytesToBase64(iv),
        };
    };

    const decryptMessageResult = async (
        ciphertext: string,
        iv: string,
    ): Promise<DecryptedSecretMessage> => {
        if (!sharedKey.value) {
            return {
                body: '[Encrypted message: key not ready]',
                decrypted: false,
            };
        }

        try {
            const plaintext = await crypto.subtle.decrypt(
                {
                    name: 'AES-GCM',
                    iv: base64ToBytes(iv) as BufferSource,
                },
                sharedKey.value,
                base64ToBytes(ciphertext) as BufferSource,
            );

            return {
                body: new TextDecoder().decode(plaintext),
                decrypted: true,
            };
        } catch {
            return {
                body: '[Encrypted message: cannot decrypt]',
                decrypted: false,
            };
        }
    };

    const decryptMessage = async (
        ciphertext: string,
        iv: string,
    ): Promise<string> =>
        (await decryptMessageResult(ciphertext, iv)).body;

    watch(
        [currentRoom, secretChat],
        () => {
            participants.value = [...(secretChat.value?.participants ?? [])];
            void setup();
        },
        { immediate: true },
    );

    return {
        fingerprint,
        safetyNumber,
        sharedKey,
        status,
        applyParticipant,
        decryptMessage,
        decryptMessageResult,
        encryptMessage,
        setup,
        synchronizeSender,
    };
}
