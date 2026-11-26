package agent

import (
	"context"
	"errors"
	"strings"
	"testing"

	"github.com/aws/aws-sdk-go-v2/service/kms"
	"github.com/aws/smithy-go"
)

func TestAWSKMSClientUsesExplicitTrustedRegionOverEnvironment(t *testing.T) {
	t.Setenv("AWS_REGION", "us-east-1")

	client, err := NewAWSKMSAsymmetricKeyClient(context.Background(), "us-west-2")
	if err != nil {
		t.Fatalf("NewAWSKMSAsymmetricKeyClient error = %v", err)
	}

	kmsClient, ok := client.api.(*kms.Client)
	if !ok {
		t.Fatalf("AWS KMS API type = %T; want *kms.Client", client.api)
	}
	if got := kmsClient.Options().Region; got != "us-west-2" {
		t.Fatalf("AWS KMS client Region = %q; want trusted runtime Region %q", got, "us-west-2")
	}
}

func TestAWSKMSClientReportsSafeFailureCategory(t *testing.T) {
	api := &fakeAWSKMSAPI{
		getPublicKeyErr: &smithy.GenericAPIError{
			Code: "AccessDeniedException", Message: "private credential detail",
		},
	}
	client, err := newAWSKMSAsymmetricKeyClient(api)
	if err != nil {
		t.Fatal(err)
	}
	_, err = client.GetPublicKey(context.Background(), "test-key")
	if !errors.Is(err, ErrAWSKMSOperationFailed) {
		t.Fatalf("GetPublicKey error = %v; want ErrAWSKMSOperationFailed", err)
	}
	if !strings.Contains(err.Error(), "AccessDeniedException") {
		t.Fatalf("GetPublicKey error = %v; want safe AWS error code", err)
	}
	if strings.Contains(err.Error(), "private credential detail") {
		t.Fatalf("GetPublicKey error exposed AWS error message: %v", err)
	}
}
