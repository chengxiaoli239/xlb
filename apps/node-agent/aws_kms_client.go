package agent

import (
	"context"
	"errors"
	"fmt"

	awsconfig "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/service/kms"
	"github.com/aws/aws-sdk-go-v2/service/kms/types"
	"github.com/aws/smithy-go"
)

var ErrAWSKMSOperationFailed = errors.New("AWS KMS node identity operation failed")

type awsKMSAPI interface {
	GetPublicKey(context.Context, *kms.GetPublicKeyInput, ...func(*kms.Options)) (*kms.GetPublicKeyOutput, error)
	Sign(context.Context, *kms.SignInput, ...func(*kms.Options)) (*kms.SignOutput, error)
}

type AWSKMSAsymmetricKeyClient struct {
	api awsKMSAPI
}

func NewAWSKMSAsymmetricKeyClient(ctx context.Context, region string) (*AWSKMSAsymmetricKeyClient, error) {
	if ctx == nil {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	loadOptions := make([]func(*awsconfig.LoadOptions) error, 0, 1)
	if region != "" {
		loadOptions = append(loadOptions, awsconfig.WithRegion(region))
	}
	config, err := awsconfig.LoadDefaultConfig(ctx, loadOptions...)
	if err != nil {
		return nil, awsKMSOperationError("load AWS credential configuration", err)
	}
	return &AWSKMSAsymmetricKeyClient{api: kms.NewFromConfig(config)}, nil
}

func newAWSKMSAsymmetricKeyClient(api awsKMSAPI) (*AWSKMSAsymmetricKeyClient, error) {
	if api == nil {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	return &AWSKMSAsymmetricKeyClient{api: api}, nil
}

func (client *AWSKMSAsymmetricKeyClient) GetPublicKey(
	ctx context.Context,
	keyID string,
) (KMSPublicKey, error) {
	if client == nil || client.api == nil || ctx == nil || keyID == "" {
		return KMSPublicKey{}, ErrInvalidKMSNodeTLSIdentity
	}
	result, err := client.api.GetPublicKey(ctx, &kms.GetPublicKeyInput{KeyId: &keyID})
	if err != nil {
		return KMSPublicKey{}, awsKMSOperationError("GetPublicKey", err)
	}
	if result == nil {
		return KMSPublicKey{}, ErrAWSKMSOperationFailed
	}
	return KMSPublicKey{
		DER:               append([]byte(nil), result.PublicKey...),
		KeySpec:           string(result.KeySpec),
		KeyUsage:          string(result.KeyUsage),
		SigningAlgorithms: signingAlgorithmNames(result.SigningAlgorithms),
	}, nil
}

func (client *AWSKMSAsymmetricKeyClient) Sign(
	ctx context.Context,
	keyID string,
	digest []byte,
) ([]byte, error) {
	if client == nil || client.api == nil || ctx == nil || keyID == "" || len(digest) != 32 {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	messageType := types.MessageTypeDigest
	algorithm := types.SigningAlgorithmSpecEcdsaSha256
	result, err := client.api.Sign(ctx, &kms.SignInput{
		KeyId:            &keyID,
		Message:          append([]byte(nil), digest...),
		MessageType:      messageType,
		SigningAlgorithm: algorithm,
	})
	if err != nil {
		return nil, awsKMSOperationError("Sign", err)
	}
	if result == nil || result.SigningAlgorithm != algorithm || len(result.Signature) == 0 {
		return nil, ErrAWSKMSOperationFailed
	}
	return append([]byte(nil), result.Signature...), nil
}

func awsKMSOperationError(operation string, err error) error {
	var apiError smithy.APIError
	if errors.As(err, &apiError) && validRuntimeToken(apiError.ErrorCode(), 64) {
		return fmt.Errorf("%w: %s (%s)", ErrAWSKMSOperationFailed, operation, apiError.ErrorCode())
	}
	return fmt.Errorf("%w: %s (%T)", ErrAWSKMSOperationFailed, operation, err)
}

func signingAlgorithmNames(algorithms []types.SigningAlgorithmSpec) []string {
	names := make([]string, len(algorithms))
	for index, algorithm := range algorithms {
		names[index] = string(algorithm)
	}
	return names
}
