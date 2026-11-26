package agent

import (
	"context"
	"testing"

	"github.com/aws/aws-sdk-go-v2/service/kms"
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
