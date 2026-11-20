package com.xlb.vpn.presentation;

import android.graphics.Rect;
import android.util.AttributeSet;

import com.journeyapps.barcodescanner.BarcodeView;

public final class OffsetBarcodeView extends BarcodeView {
    public OffsetBarcodeView(android.content.Context context, AttributeSet attrs) {
        super(context, attrs);
    }

    @Override
    protected Rect calculateFramingRect(Rect container, Rect surface) {
        Rect framing = super.calculateFramingRect(container, surface);
        if (framing == null) return null;
        int surfaceTop = surface == null ? container.top : surface.top;
        int shift = Math.min(dp(44), Math.max(0, framing.top - surfaceTop));
        framing.offset(0, -shift);
        return framing;
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density);
    }
}
